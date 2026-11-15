<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\SiteRestoreService;

/**
 * Возврат всего сайта к состоянию перед выбранным изменением:
 * файлы — из git-точки отката, база — из SQL-дампа, сделанного перед этим изменением.
 *
 * Перед откатом создаётся страховочная точка (дамп + коммит), поэтому операцию
 * можно отменить. Переписка, очередь и история изменений сохраняются.
 */
class RestoreSiteTool extends AbstractChangeTool
{
    public const DOMAIN = 'security';

    public static function getName(): string
    {
        return 'restore_site';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'restore_site',
            'description' => 'Возвращает весь сайт к состоянию перед указанным изменением: файлы — из git-коммита, база — из SQL-дампа. Всё, что сделано после, пропадёт. Перед откатом создаётся страховочная точка, из неё можно вернуться.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'change_id' => array(
                        'type' => 'integer',
                        'description' => 'ID изменения из list_changes: сайт вернётся к состоянию ПЕРЕД ним',
                    ),
                    'confirm_restore' => array(
                        'type' => 'boolean',
                        'description' => 'Подтверждение полного отката; без true операция отклоняется',
                    ),
                ),
                'required' => array('change_id', 'confirm_restore'),
            ),
        );
    }

    public static function preview(array $args, array $context = array()): array
    {
        $changeId = (int)($args['change_id'] ?? 0);
        if ($changeId <= 0) {
            throw new \InvalidArgumentException('Параметр change_id обязателен.');
        }

        if (($args['confirm_restore'] ?? false) !== true) {
            throw new \InvalidArgumentException(
                'Откат вернёт весь сайт к прошлому состоянию. Передайте confirm_restore=true, если это осознанное решение.'
            );
        }

        $change = SiteRestoreService::loadChange($changeId);
        $dump = SiteRestoreService::dumpFor($change);
        $point = SiteRestoreService::pointOf($change);

        if ($dump === null && $point === array()) {
            throw new \InvalidArgumentException(
                'Для изменения #' . $changeId . ' нет точки отката: ни SQL-дампа, ни git-коммита.'
            );
        }

        $later = SiteRestoreService::laterCount($changeId);
        $created = $change['CREATED_AT'] ?? null;
        $createdAt = $created instanceof \Bitrix\Main\Type\DateTime ? $created->format('Y-m-d H:i:s') : (string)$created;

        return array(
            'summary' => 'Откат всего сайта к состоянию перед изменением #' . $changeId,
            'payload' => array('args' => array('change_id' => $changeId)),
            'preview' => 'Сайт вернётся к состоянию перед изменением #' . $changeId . ' — «'
                . (string)$change['SUMMARY'] . '» (' . $createdAt . ').'
                . "\nФайлы: " . ($point !== array() ? 'из git-коммита' : 'вернуть нельзя — git-точки нет') . '.'
                . "\nБаза: " . ($dump !== null ? 'из дампа ' . $dump : 'вернуть нельзя — дампа нет') . '.'
                . ($later > 0 ? "\nВсё, что сделано после, пропадёт (изменений после: " . $later . ').' : '')
                . "\nПереписка и история изменений сохранятся: отменённые изменения будут помечены откаченными."
                . "\nПеред откатом создаётся страховочная точка — из неё можно вернуться.",
        );
    }

    /** @return array{change_id:int,point:array,files:array,database:?string,safety_backup:?string,safety_point:array,rolled_back:int,rejected_pending:int,elapsed:int} */
    public static function apply(array $payload): array
    {
        $changeId = (int)($payload['args']['change_id'] ?? 0);

        $result = SiteRestoreService::restore($changeId, (int)($payload['change_id'] ?? 0));
        $result['change_id'] = $changeId;
        $result['point'] = isset($payload['point']) && is_array($payload['point']) ? $payload['point'] : array();

        return $result;
    }

    public static function rollback(array $payload): void
    {
        SiteRestoreService::undo(isset($payload['result']) && is_array($payload['result']) ? $payload['result'] : array());
    }
}
