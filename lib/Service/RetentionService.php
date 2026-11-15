<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Mibazarow\Bxadmins\Config;
use Mibazarow\Bxadmins\Model\ChangeTable;
use Mibazarow\Bxadmins\Model\TaskEventTable;

/**
 * Обслуживание базы: модуль не должен раздувать сайт. Всё устаревшее удаляется
 * физически, тяжёлые поля завершённых изменений обнуляются.
 */
class RetentionService
{
    /** Статусы, при которых изменение уже не может быть применено. */
    private const FINISHED = array('executed', 'rejected', 'failed', 'rolled_back');

    /**
     * Полный проход обслуживания. Ошибки не пробрасываются — чистка не должна
     * ломать работу модуля.
     *
     * @return array{heavy_cleared:int,snapshots_deleted:int,logs_pruned:int,events_deleted:int,optimized:string[]}
     */
    public static function run(): array
    {
        $stats = array(
            'heavy_cleared' => 0,
            'snapshots_deleted' => 0,
            'logs_pruned' => 0,
            'events_deleted' => 0,
            'optimized' => array(),
        );

        try {
            $stats['heavy_cleared'] = self::clearHeavyFields();
        } catch (\Throwable $e) {
            Logger::error('Retention: очистка тяжёлых полей: ' . $e->getMessage());
        }

        try {
            $stats['snapshots_deleted'] = self::pruneSnapshots();
        } catch (\Throwable $e) {
            Logger::error('Retention: удаление снапшотов: ' . $e->getMessage());
        }

        try {
            $stats['events_deleted'] = TaskEventService::prune();
        } catch (\Throwable $e) {
            Logger::error('Retention: очистка журнала событий: ' . $e->getMessage());
        }

        try {
            Logger::prune(Config::logRetentionDays());
            $stats['logs_pruned'] = 1;
        } catch (\Throwable $e) {
            Logger::error('Retention: ротация журналов: ' . $e->getMessage());
        }

        try {
            // Скриншоты агента — временные артефакты, старше срока хранения не нужны.
            BrowserService::pruneShots(Config::retentionDays());
        } catch (\Throwable $e) {
            Logger::error('Retention: удаление скриншотов: ' . $e->getMessage());
        }

        try {
            // Журнал демона пишется из Node без ротации: подрезаем, чтобы не рос бесконечно.
            self::trimDaemonLog();
        } catch (\Throwable $e) {
            Logger::error('Retention: подрезка журнала демона: ' . $e->getMessage());
        }

        try {
            $stats['optimized'] = self::optimizeTables();
        } catch (\Throwable $e) {
            Logger::error('Retention: OPTIMIZE TABLE: ' . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Обнуляет PREVIEW сразу после завершения и PAYLOAD по истечении срока:
     * текст правки больше не нужен, а места занимает больше всего.
     */
    private static function clearHeavyFields(): int
    {
        $cleared = 0;

        // PREVIEW — только для карточки в чате, после завершения бесполезен.
        $rows = ChangeTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=STATUS' => self::FINISHED, '!PREVIEW' => false),
            'limit' => 500,
        ))->fetchAll();

        foreach ($rows as $row) {
            ChangeTable::update((int)$row['ID'], array('PREVIEW' => null));
            $cleared++;
        }

        // PAYLOAD нужен для отката; после срока хранения откат уже не актуален.
        $threshold = DateTime::createFromPhp(new \DateTime('-' . Config::retentionDays() . ' days'));
        $rows = ChangeTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=STATUS' => self::FINISHED, '<CREATED_AT' => $threshold),
            'limit' => 500,
        ))->fetchAll();

        foreach ($rows as $row) {
            ChangeTable::update((int)$row['ID'], array(
                'PAYLOAD' => '{}',
                'RESULT' => null,
            ));
            $cleared++;
        }

        return $cleared;
    }

    /** Удаляет каталоги снапшотов, у которых больше нет изменения. */
    private static function pruneSnapshots(): int
    {
        $base = LocalFileGuard::root() . FileSnapshotService::DIR;
        if (!is_dir($base)) {
            return 0;
        }

        $existing = array();
        $rows = ChangeTable::getList(array('select' => array('ID')))->fetchAll();
        foreach ($rows as $row) {
            $existing[(int)$row['ID']] = true;
        }

        $deleted = 0;

        foreach (scandir($base) ?: array() as $entry) {
            if ($entry === '.' || $entry === '..' || !ctype_digit($entry)) {
                continue;
            }

            if (isset($existing[(int)$entry])) {
                continue;
            }

            self::deleteDirectory($base . '/' . $entry);
            $deleted++;
        }

        return $deleted;
    }

    /** Возвращает место файловой системе после массовых удалений. */
    private static function optimizeTables(): array
    {
        $connection = Application::getConnection();
        $optimized = array();

        foreach (array(ChangeTable::getTableName(), TaskEventTable::getTableName()) as $table) {
            try {
                $connection->queryExecute('OPTIMIZE TABLE ' . $table);
                $optimized[] = $table;
            } catch (\Throwable $e) {
                Logger::error('Retention: OPTIMIZE ' . $table . ': ' . $e->getMessage());
            }
        }

        return $optimized;
    }

    private static function deleteDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: array() as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    /**
     * Держит daemon.log в разумных размерах: демон дописывает файл в режиме append,
     * поэтому обрезка безопасна — новые записи всё равно уходят в конец.
     */
    private static function trimDaemonLog(): void
    {
        $path = Config::logDir() . '/daemon.log';
        $limit = 10 * 1024 * 1024;

        if (!is_file($path) || (int)@filesize($path) < $limit) {
            return;
        }

        $tail = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($tail)) {
            return;
        }

        // Оставляем последние 2000 строк — свежая диагностика важнее старых записей.
        $kept = array_slice($tail, -2000);
        @file_put_contents($path, implode("\n", $kept) . "\n");
    }
}
