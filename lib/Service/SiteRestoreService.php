<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Mibazarow\Bxadmins\Model\ChangeTable;

/**
 * Возврат сайта к состоянию перед изменением: файлы из git-точки, база из SQL-дампа.
 *
 * Используется инструментом restore_site и как откат для разрушительных операций,
 * которые нельзя вернуть точечно (удаление инфоблока, раздела, темы форума, модуля).
 * Перед восстановлением создаётся страховочная точка, поэтому откат обратим.
 */
class SiteRestoreService
{
    public static function loadChange(int $changeId): array
    {
        $change = $changeId > 0 ? ChangeTable::getById($changeId)->fetch() : false;
        if (!$change) {
            throw new \InvalidArgumentException('Изменение #' . $changeId . ' не найдено.');
        }

        return $change;
    }

    /** @return array{site?:string,local?:string} */
    public static function pointOf(array $change): array
    {
        $raw = trim((string)($change['RESULT'] ?? ''));
        if ($raw === '') {
            return array();
        }

        try {
            $result = Json::decode($raw);
        } catch (\Throwable $e) {
            return array();
        }

        if (!is_array($result) || !isset($result['point']) || !is_array($result['point'])) {
            return array();
        }

        $point = array();
        foreach (array('site', 'local') as $kind) {
            $sha = trim((string)($result['point'][$kind] ?? ''));
            if ($sha !== '') {
                $point[$kind] = $sha;
            }
        }

        return $point;
    }

    /** Дамп, сделанный перед изменением (или ближайший более ранний сохранившийся). */
    public static function dumpFor(array $change): ?string
    {
        $available = array();
        foreach (BackupService::list() as $item) {
            $available[$item['file']] = true;
        }

        $own = trim((string)($change['BACKUP_FILE'] ?? ''));
        if ($own !== '') {
            // Свой дамп был, но его уже удалила ротация: чужой дамп вернул бы базу
            // к более раннему состоянию, чем просили. Честнее вернуть только файлы.
            return isset($available[$own]) ? $own : null;
        }

        $rows = ChangeTable::getList(array(
            'select' => array('ID', 'BACKUP_FILE'),
            'filter' => array('<ID' => (int)$change['ID'], '!BACKUP_FILE' => false),
            'order' => array('ID' => 'DESC'),
            'limit' => 10,
        ))->fetchAll();

        foreach ($rows as $row) {
            $file = trim((string)($row['BACKUP_FILE'] ?? ''));
            if ($file !== '' && isset($available[$file])) {
                return $file;
            }
        }

        return null;
    }

    public static function laterCount(int $changeId): int
    {
        return (int)ChangeTable::getCount(array('>ID' => $changeId));
    }

    /**
     * Восстанавливает файлы и базу к состоянию перед изменением $changeId.
     *
     * @param int $currentId запись, которую не нужно помечать откаченной (сам откат)
     * @param bool $requireDatabase true — без дампа восстановление не начинать
     *                              (данные иначе останутся удалёнными)
     * @return array{files:array,database:?string,safety_backup:?string,safety_point:array,rolled_back:int,rejected_pending:int,rolled_back_ids:int[],rejected_ids:int[],elapsed:int}
     */
    public static function restore(int $changeId, int $currentId, bool $requireDatabase = false): array
    {
        $change = self::loadChange($changeId);
        $dump = self::dumpFor($change);
        $point = self::pointOf($change);

        if ($dump === null && $requireDatabase) {
            throw new \RuntimeException(
                'Дамп базы для изменения #' . $changeId . ' не сохранился — восстановить данные нельзя.'
            );
        }

        if ($dump === null && $point === array()) {
            throw new \RuntimeException('Для изменения #' . $changeId . ' нет точки отката.');
        }

        $started = microtime(true);

        // Страховка: текущие файлы коммитятся до восстановления.
        $safetyPoint = GitService::point('BXADMIN-S: страховка перед откатом к #' . $changeId);

        $files = $point !== array()
            ? GitService::restore($point)
            : array('site' => 'точки нет', 'local' => 'точки нет');

        // BackupService::restore сам создаёт страховочный дамп текущей базы.
        // Служебные таблицы модуля не восстанавливаем: очередь, переписка и история
        // изменений должны пережить откат, иначе текущий запрос к демону потеряет задачу.
        $db = $dump !== null ? BackupService::restore($dump, false) : null;

        // История остаётся, но отменённые изменения помечаем откаченными,
        // а устаревшие предложения — отклонёнными.
        $history = $db !== null
            ? self::reconcileHistory($changeId, $currentId)
            : array('rolled_back' => 0, 'rejected' => 0, 'rolled_back_ids' => array(), 'rejected_ids' => array());

        return array(
            'files' => $files,
            'database' => $dump,
            'safety_backup' => $db !== null ? (string)$db['safety_backup'] : null,
            'safety_point' => $safetyPoint,
            'rolled_back' => $history['rolled_back'],
            'rejected_pending' => $history['rejected'],
            'rolled_back_ids' => $history['rolled_back_ids'],
            'rejected_ids' => $history['rejected_ids'],
            'elapsed' => (int)round(microtime(true) - $started),
        );
    }

    /** Отменяет выполненное восстановление по его результату. */
    public static function undo(array $result): void
    {
        $safetyPoint = isset($result['safety_point']) && is_array($result['safety_point']) ? $result['safety_point'] : array();
        if ($safetyPoint !== array()) {
            GitService::restore($safetyPoint);
        }

        $safetyBackup = trim((string)($result['safety_backup'] ?? ''));
        if ($safetyBackup !== '') {
            BackupService::restore($safetyBackup, false);
        }

        // Возвращаем прежние статусы изменениям, которые пометил откат.
        self::restoreStatuses((array)($result['rolled_back_ids'] ?? array()), ChangeService::STATUS_EXECUTED);
        self::restoreStatuses((array)($result['rejected_ids'] ?? array()), ChangeService::STATUS_PENDING);
    }

    /**
     * Приводит историю к состоянию после отката: изменения начиная с целевого
     * помечаются откаченными, устаревшие предложения — отклонёнными.
     *
     * @return array{rolled_back:int,rejected:int,rolled_back_ids:int[],rejected_ids:int[]}
     */
    private static function reconcileHistory(int $targetId, int $currentId): array
    {
        $rows = ChangeTable::getList(array(
            'select' => array('ID', 'STATUS'),
            'filter' => array('>=ID' => $targetId),
            'limit' => 1000,
        ))->fetchAll();

        $rolledBack = 0;
        $rejected = 0;
        $rolledBackIds = array();
        $rejectedIds = array();

        foreach ($rows as $row) {
            $id = (int)$row['ID'];
            if ($id === $currentId) {
                continue;
            }

            $status = (string)$row['STATUS'];

            if ($status === ChangeService::STATUS_PENDING) {
                ChangeTable::update($id, array(
                    'STATUS' => ChangeService::STATUS_REJECTED,
                    'UPDATED_AT' => new DateTime(),
                ));
                $rejected++;
                $rejectedIds[] = $id;
            } elseif ($status === ChangeService::STATUS_EXECUTED) {
                ChangeTable::update($id, array(
                    'STATUS' => ChangeService::STATUS_ROLLED_BACK,
                    'UPDATED_AT' => new DateTime(),
                ));
                $rolledBack++;
                $rolledBackIds[] = $id;
            }
        }

        return array(
            'rolled_back' => $rolledBack,
            'rejected' => $rejected,
            'rolled_back_ids' => $rolledBackIds,
            'rejected_ids' => $rejectedIds,
        );
    }

    /** @param int[] $ids */
    private static function restoreStatuses(array $ids, string $status): void
    {
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0) {
                continue;
            }

            if (!ChangeTable::getById($id)->fetch()) {
                continue;
            }

            ChangeTable::update($id, array(
                'STATUS' => $status,
                'UPDATED_AT' => new DateTime(),
            ));
        }
    }
}
