<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\DateTime;
use Mibazarow\Bxadmins\Config;
use Mibazarow\Bxadmins\Model\IndexTable;

/**
 * Полная индексация сайта: сбор документов, эмбеддинги, курсор и блокировка от параллельных запусков.
 */
class IndexService
{
    public const AGENT_CODE = '\Mibazarow\Bxadmins\Agent\IndexAgent::run();';

    // EmbeddingGemma 2 читает 512 токенов: всё, что дальше, сервис молча отбрасывает.
    // 1500 символов гарантированно попадают в окно для русского текста и кода.
    private const CHUNK_SIZE = 1500;
    private const CHUNK_OVERLAP = 200;
    private const MAX_DOCS = 3000;
    private const MAX_FILES_PER_GROUP = 300;
    private const MAX_FILE_SIZE = 1048576;
    private const MAX_CODE_FILE_SIZE = 204800;
    private const LOCK_TTL = 180;
    /** Интервал постоянного агента: 15 минут. */
    private const SYNC_INTERVAL = 900;
    /** true — список документов обрезан лимитами, удалять устаревшие строки нельзя. */
    private static bool $truncated = false;

    /**
     * Детерминированный список документов индекса (порядок и лимиты фиксированы).
     *
     * @return array<int, array{source_type:string, source_id:string, title:string, content:string}>
     */
    private static function documents(): array
    {
        self::$truncated = false;
        $documents = array();

        $groups = array(
            array('module', self::moduleDocuments()),
            array('code', self::codeDocuments()),
            array('component', self::componentDocuments()),
            array('template', self::templateDocuments()),
            array('iblock', self::iblockDocuments()),
            array('event', self::eventDocuments()),
        );

        foreach ($groups as [$sourceType, $groupDocs]) {
            foreach ($groupDocs as $doc) {
                // Группы module/iblock/event собираются целиком и могут превышать окно модели.
                foreach (self::chunkDocument($doc) as $chunk) {
                    if (count($documents) >= self::MAX_DOCS) {
                        self::$truncated = true;
                        break 3;
                    }
                    $documents[] = $chunk + array('source_type' => $sourceType);
                }
            }
        }

        return $documents;
    }

    /** Полная индексация завершена (пройден весь список документов). */
    public static function isComplete(): bool
    {
        return self::getOption('index_complete', 'N') === 'Y';
    }

    /**
     * Обрабатывает до $limit документов начиная с сохранённого курсора.
     *
     * @return array{processed:int, skipped:int, total:int, offset:int, done:bool, errors:array, busy?:bool, already_complete?:bool}
     */
    public static function runBatch(int $limit = 10, bool $reindex = false): array
    {
        $lock = (int)self::getOption('index_lock', '0');
        if ($lock > 0 && time() - $lock < self::LOCK_TTL) {
            return array('busy' => true);
        }

        self::setOption('index_lock', (string)time());

        try {
            // Первичная (полная) индексация — по курсору; после неё — только изменения.
            return (!$reindex && self::isComplete())
                ? self::syncIncremental($limit)
                : self::doRunBatch($limit);
        } finally {
            self::setOption('index_lock', '0');
        }
    }

    /**
     * Инкрементальная синхронизация: обновляет только изменившиеся документы
     * и удаляет строки, которых больше нет на сайте.
     *
     * @return array{processed:int,skipped:int,deleted:int,pending:int,total:int,offset:int,done:bool,errors:array,incremental:bool}
     */
    private static function syncIncremental(int $limit = 20): array
    {
        $limit = max(1, min(20, $limit));
        $documents = self::documents();
        $total = count($documents);

        $known = array();
        $rows = IndexTable::getList(array(
            'select' => array('ID', 'SOURCE_TYPE', 'SOURCE_ID', 'HASH'),
            'limit' => self::MAX_DOCS + 500,
        ))->fetchAll();

        foreach ($rows as $row) {
            $known[$row['SOURCE_TYPE'] . '|' . $row['SOURCE_ID']] = $row;
        }

        $seen = array();
        $pending = array();

        foreach ($documents as $doc) {
            $key = $doc['source_type'] . '|' . $doc['source_id'];
            $seen[$key] = true;

            $row = $known[$key] ?? null;
            if ($row === null || (string)$row['HASH'] !== md5(Utf8Helper::sanitize($doc['content']))) {
                $pending[] = $doc;
            }
        }

        // Удалять устаревшие строки можно только если список документов не обрезан лимитами.
        $deleted = 0;
        if (!self::$truncated) {
            foreach ($known as $key => $row) {
                if (!isset($seen[$key])) {
                    IndexTable::delete((int)$row['ID']);
                    $deleted++;
                }
            }
        }

        [$processed, , $errors] = self::indexDocuments(array_slice($pending, 0, $limit));

        self::setOption('index_total', (string)$total);
        self::setOption('index_last_run', (new DateTime())->format('Y-m-d H:i:s'));
        if ($errors === array()) {
            self::setOption('index_last_error', '');
        }

        return array(
            'processed' => $processed,
            'skipped' => $total - count($pending),
            'deleted' => $deleted,
            'pending' => max(0, count($pending) - $processed),
            'total' => $total,
            'offset' => $total,
            'done' => true,
            'errors' => $errors,
            'incremental' => true,
        );
    }

    /**
     * Векторизует и сохраняет документы; неизменённые пропускаются по хешу.
     *
     * @param array<int, array{source_type:string, source_id:string, title:string, content:string}> $documents
     * @return array{0:int,1:int,2:string[]} [обработано, пропущено, ошибки]
     */
    private static function indexDocuments(array $documents): array
    {
        $processed = 0;
        $skipped = 0;
        $errors = array();
        $embedder = new EmbeddingClient();

        foreach ($documents as $doc) {
            try {
                $content = Utf8Helper::sanitize($doc['content']);
                $hash = md5($content);
                $existing = IndexTable::getList(array(
                    'select' => array('ID', 'HASH'),
                    'filter' => array(
                        '=SOURCE_TYPE' => $doc['source_type'],
                        '=SOURCE_ID' => $doc['source_id'],
                    ),
                    'limit' => 1,
                ))->fetch();

                if ($existing && (string)$existing['HASH'] === $hash) {
                    $skipped++;
                    continue;
                }

                $vector = $embedder->embed($doc['content'], EmbeddingClient::PROFILE_PASSAGE);

                $fields = array(
                    'SOURCE_TYPE' => $doc['source_type'],
                    'SOURCE_ID' => $doc['source_id'],
                    'TITLE' => $doc['title'] !== '' ? mb_substr(Utf8Helper::sanitize($doc['title']), 0, 500) : null,
                    'CONTENT' => $content,
                    'EMBEDDING' => pack('g*', ...$vector),
                    'DIM' => count($vector),
                    'HASH' => $hash,
                    'UPDATED_AT' => new DateTime(),
                );

                if ($existing) {
                    IndexTable::update($existing['ID'], $fields);
                } else {
                    self::insertOrUpdate($doc, $fields);
                }

                $processed++;
            } catch (\Throwable $e) {
                Logger::error('Индексация: ошибка документа ' . $doc['source_id'] . ': ' . $e->getMessage(), array(
                    'source_type' => $doc['source_type'],
                ));
                $errors[] = $doc['source_id'] . ': ' . $e->getMessage();
                self::setOption('index_last_error', $e->getMessage());
            }
        }

        return array($processed, $skipped, $errors);
    }



    /**
     * Режет документ, не помещающийся в окно модели. Файловые документы уже
     * нарезаны в fileDocuments(), поэтому для них это no-op.
     *
     * @param array{source_id:string,title:string,content:string} $doc
     * @return array<int, array{source_id:string,title:string,content:string}>
     */
    private static function chunkDocument(array $doc): array
    {
        $length = mb_strlen($doc['content']);
        if ($length <= self::CHUNK_SIZE) {
            return array($doc);
        }

        $chunks = array();
        $number = 0;

        for ($start = 0; $start < $length; $start += self::CHUNK_SIZE - self::CHUNK_OVERLAP) {
            $chunks[] = array(
                'source_id' => $doc['source_id'] . '#' . $number,
                'title' => $doc['title'] . ' (часть ' . ($number + 1) . ')',
                'content' => mb_substr($doc['content'], $start, self::CHUNK_SIZE),
            );
            $number++;
        }

        return $chunks;
    }

    /**
     * Вставляет документ. Если строку успел создать параллельный прогон
     * (уникальный ключ SOURCE_TYPE+SOURCE_ID), обновляет существующую.
     *
     * @param array{source_type:string,source_id:string} $doc
     */
    private static function insertOrUpdate(array $doc, array $fields): void
    {
        try {
            $result = IndexTable::add($fields);
            if ($result->isSuccess()) {
                return;
            }
            $message = implode('; ', $result->getErrorMessages());
        } catch (\Throwable $e) {
            $message = $e->getMessage();
        }

        $raced = IndexTable::getList(array(
            'select' => array('ID'),
            'filter' => array(
                '=SOURCE_TYPE' => $doc['source_type'],
                '=SOURCE_ID' => $doc['source_id'],
            ),
            'limit' => 1,
        ))->fetch();

        if (!$raced) {
            throw new \RuntimeException($message);
        }

        IndexTable::update((int)$raced['ID'], $fields);
    }

    /** Переводит агента на постоянный режим: раз в 15 минут только изменения. */
    private static function scheduleSync(): void
    {
        $existing = \CAgent::GetList(
            array('ID' => 'ASC'),
            array('=NAME' => self::AGENT_CODE, '=MODULE_ID' => Config::MODULE_ID)
        )->Fetch();

        if ($existing) {
            \CAgent::Update((int)$existing['ID'], array(
                'AGENT_INTERVAL' => self::SYNC_INTERVAL,
                'IS_PERIOD' => 'Y',
                'NEXT_EXEC' => DateTime::createFromPhp(new \DateTime('+' . self::SYNC_INTERVAL . ' seconds')),
            ));
        }
    }

    /** Снимает отметку завершения и запускает фоновый агент полной переиндексации. */
    public static function startReindex(): void
    {
        self::setOption('index_offset', '0');
        self::setOption('index_total', '0');
        self::setOption('index_complete', 'N');
        self::setOption('index_started_at', (string)time());
        self::setOption('index_last_error', '');
        self::registerAgent();
    }

    /**
     * Регистрирует агента индексации (без дублей).
     *
     * Легаси-исключение: CAgent — штатный механизм фоновых агентов Битрикса,
     * D7-аналога регистрации агентов в ядре нет.
     *
     * @return bool false — агент не создан (причина в журнале/исключении ядра)
     */
    public static function registerAgent(): bool
    {
        // Если агент уже есть (например, остановился после завершения индексации),
        // не плодим дубликат, а включаем и обновляем его.
        $existing = \CAgent::GetList(
            array('ID' => 'ASC'),
            array('=NAME' => self::AGENT_CODE, '=MODULE_ID' => Config::MODULE_ID)
        )->Fetch();

        // Пока идёт полная индексация — без пауз (каждый тик cron);
        // после неё scheduleSync() переводит агента на 15 минут.
        $interval = self::isComplete() ? self::SYNC_INTERVAL : 0;

        if ($existing) {
            return (bool)\CAgent::Update((int)$existing['ID'], array(
                'ACTIVE' => 'Y',
                'AGENT_INTERVAL' => $interval,
                'IS_PERIOD' => $interval > 0 ? 'Y' : 'N',
            ));
        }


        // Пустой NEXT_EXEC — штатное «запустить сейчас»: CAgent::Add подставит
        // текущее время БД сам (не зависим от формата даты сайта/CLI-контекста).
        $agentId = \CAgent::AddAgent(
            self::AGENT_CODE,
            Config::MODULE_ID,
            $interval > 0 ? 'Y' : 'N',
            $interval,
            '',
            'Y',
            '',
            100,
            false,
            false
        );

        return (int)$agentId > 0;
    }

    private static function doRunBatch(int $limit): array
    {
        $limit = max(1, min(20, $limit));
        $offset = self::cursorOffset();
        $documents = self::documents();
        $total = count($documents);
        $slice = array_slice($documents, $offset, $limit);

        if (self::getOption('index_started_at', '0') === '0') {
            self::setOption('index_started_at', (string)time());
        }

        [$processed, $skipped, $errors] = self::indexDocuments($slice);

        $newOffset = $offset + count($slice);
        $done = $newOffset >= $total;

        // Ошибки в этом батче не было — старый текст ошибки больше не актуален.
        if ($errors === array()) {
            self::setOption('index_last_error', '');
        }

        if ($done) {
            self::setOption('index_offset', '0');
            self::setOption('index_total', (string)$total);
            self::setOption('index_last_run', (new DateTime())->format('Y-m-d H:i:s'));
            self::setOption('index_complete', 'Y');
            // Полная индексация закончена — агент переходит на редкий инкрементальный режим.
            self::scheduleSync();
        } else {
            self::setOption('index_offset', (string)$newOffset);
            self::setOption('index_complete', 'N');
        }

        return array(
            'processed' => $processed,
            'skipped' => $skipped,
            'total' => $total,
            'offset' => $done ? $total : $newOffset,
            'done' => $done,
            'errors' => $errors,
        );
    }

    /** Снимок состояния индекса для страницы «Управление». */
    public static function status(): array
    {
        // Счётчики из БД одним запросом: обход файловой системы — только при индексации.
        $byType = array(
            'module' => 0,
            'code' => 0,
            'component' => 0,
            'iblock' => 0,
            'event' => 0,
            'template' => 0,
        );

        $rows = IndexTable::getList(array(
            'select' => array('SOURCE_TYPE', 'CNT'),
            'runtime' => array(
                new ExpressionField('CNT', 'COUNT(*)'),
            ),
            'group' => array('SOURCE_TYPE'),
        ))->fetchAll();

        $rowsCount = 0;
        foreach ($rows as $row) {
            $type = (string)$row['SOURCE_TYPE'];
            $count = (int)$row['CNT'];
            $byType[$type] = $count;
            $rowsCount += $count;
        }

        $complete = self::isComplete();
        $offset = self::cursorOffset();
        $total = (int)self::getOption('index_total', '0');

        return array(
            'chunks' => $rowsCount,
            'by_type' => $byType,
            'rows' => $rowsCount,
            'offset' => $offset,
            'total' => $total,
            'complete' => $complete,
            'progress_percent' => self::progressPercent($complete, $offset, $total),
            'started_at' => self::formatTimestamp((int)self::getOption('index_started_at', '0')),
            'last_run' => self::getOption('index_last_run', ''),
            'last_error' => self::getOption('index_last_error', ''),
            'embedding_model' => Config::embeddingModel(),
        );
    }

    /** Удаляет все строки индекса и сбрасывает курсор. */
    public static function clear(): void
    {
        IndexTable::deleteByFilter(array('>ID' => 0));
        self::setOption('index_offset', '0');
        self::setOption('index_total', '0');
        self::setOption('index_last_error', '');
        self::setOption('index_complete', 'N');
        self::setOption('index_started_at', '0');
        self::setOption('index_last_run', '');
        self::setOption('index_lock', '0');
    }

    /** 0..100; 100 — готово; null — прогресс ещё неизвестен (индексация не начиналась). */
    private static function progressPercent(bool $complete, int $offset, int $total): ?int
    {
        if ($complete) {
            return 100;
        }

        if ($total <= 0) {
            return null;
        }

        return (int)min(100, max(0, round($offset / $total * 100)));
    }

    private static function formatTimestamp(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '';
    }

    // ---------- document builders ----------

    private static function moduleDocuments(): array
    {
        $documents = array();
        $documentRoot = Loader::getDocumentRoot();

        $modules = ModuleManager::getInstalledModules();
        usort($modules, static function (array $a, array $b): int {
            return strcmp((string)$a['ID'], (string)$b['ID']);
        });

        foreach ($modules as $row) {
            $moduleId = (string)($row['ID'] ?? '');
            if ($moduleId === '') {
                continue;
            }

            $path = is_dir($documentRoot . '/local/modules/' . $moduleId)
                ? '/local/modules/' . $moduleId
                : (is_dir($documentRoot . '/bitrix/modules/' . $moduleId) ? '/bitrix/modules/' . $moduleId : '');

            $content = "Модуль: {$moduleId}\nВерсия: " . ModuleManager::getVersion($moduleId)
                . "\nПуть: {$path}\nУстановлен: Y";

            $documents[] = array(
                'source_id' => 'module:' . $moduleId,
                'title' => 'Модуль ' . $moduleId,
                'content' => $content,
            );
        }

        return $documents;
    }

    private static function codeDocuments(): array
    {
        $files = array();

        foreach (glob(Loader::getDocumentRoot() . '/local/modules/*', GLOB_ONLYDIR) ?: array() as $moduleDir) {
            foreach (array('lib', 'admin') as $sub) {
                $dir = $moduleDir . '/' . $sub;
                if (is_dir($dir)) {
                    $files = array_merge($files, self::collectFiles($dir, array('php'), self::MAX_CODE_FILE_SIZE));
                }
            }
        }

        return self::fileDocuments('code', $files);
    }

    private static function componentDocuments(): array
    {
        $files = array();

        foreach (glob(Loader::getDocumentRoot() . '/local/components/*', GLOB_ONLYDIR) ?: array() as $vendorDir) {
            $files = array_merge($files, self::collectFiles($vendorDir, array('php'), self::MAX_FILE_SIZE));
        }

        return self::fileDocuments('component', $files);
    }

    private static function templateDocuments(): array
    {
        $files = array();

        foreach (glob(Loader::getDocumentRoot() . '/local/templates/*', GLOB_ONLYDIR) ?: array() as $templateDir) {
            $files = array_merge($files, self::collectFiles($templateDir, array('php', 'css', 'scss', 'js'), self::MAX_FILE_SIZE));
        }

        return self::fileDocuments('template', $files);
    }

    private static function iblockDocuments(): array
    {
        if (!Loader::includeModule('iblock')) {
            return array();
        }

        $documents = array();

        $iblocks = IblockTable::getList(array(
            'select' => array('ID', 'CODE', 'NAME', 'IBLOCK_TYPE_ID', 'ACTIVE', 'SORT'),
            'order' => array('ID' => 'ASC'),
            'limit' => self::MAX_FILES_PER_GROUP + 1,
        ))->fetchAll();

        if (count($iblocks) > self::MAX_FILES_PER_GROUP) {
            self::$truncated = true;
            $iblocks = array_slice($iblocks, 0, self::MAX_FILES_PER_GROUP);
        }

        foreach ($iblocks as $iblock) {
            $lines = array(
                'Инфоблок ID: ' . $iblock['ID'],
                'Название: ' . $iblock['NAME'],
                'Код: ' . (string)($iblock['CODE'] ?? ''),
                'Тип инфоблока: ' . (string)($iblock['IBLOCK_TYPE_ID'] ?? ''),
                'Активен: ' . ($iblock['ACTIVE'] === 'Y' ? 'Y' : 'N'),
                'Сортировка: ' . (int)$iblock['SORT'],
                'Свойства:',
            );

            $properties = PropertyTable::getList(array(
                'select' => array('ID', 'CODE', 'NAME', 'PROPERTY_TYPE', 'MULTIPLE', 'IS_REQUIRED'),
                'filter' => array('=IBLOCK_ID' => $iblock['ID']),
                'order' => array('ID' => 'ASC'),
                'limit' => 200,
            ))->fetchAll();

            if ($properties === array()) {
                $lines[] = '  (нет свойств)';
            }

            foreach ($properties as $property) {
                $lines[] = '  - ID ' . $property['ID'] . ': ' . $property['NAME']
                    . ' | код: ' . (string)($property['CODE'] ?? '')
                    . ' | тип: ' . $property['PROPERTY_TYPE']
                    . ' | множественное: ' . ($property['MULTIPLE'] === 'Y' ? 'Y' : 'N')
                    . ' | обязательное: ' . ($property['IS_REQUIRED'] === 'Y' ? 'Y' : 'N');
            }

            $documents[] = array(
                'source_id' => 'iblock:' . $iblock['ID'],
                'title' => 'Инфоблок ' . $iblock['ID'] . ': ' . $iblock['NAME'],
                'content' => implode("\n", $lines),
            );
        }

        return $documents;
    }

    private static function eventDocuments(): array
    {
        // Прямое чтение БД для аналитики/индексации (разрешено); записи идут только через ORM.
        // D7-сущности для b_module_to_module в ядре нет — используем D7-соединение.
        $result = Application::getConnection()->query(
            'SELECT TO_MODULE_ID, FROM_MODULE_ID, MESSAGE_ID, TO_PATH, TO_CLASS, TO_METHOD, SORT '
            . 'FROM b_module_to_module ORDER BY TO_MODULE_ID ASC, MESSAGE_ID ASC, SORT ASC, ID ASC'
        );

        $byModule = array();
        while ($row = $result->fetch()) {
            $callback = trim((string)($row['TO_PATH'] ?? '') . ' ' . (string)($row['TO_CLASS'] ?? '')
                . ' ' . (string)($row['TO_METHOD'] ?? ''));
            $byModule[(string)$row['TO_MODULE_ID']][] = '  - ' . $row['MESSAGE_ID']
                . ' | от модуля: ' . $row['FROM_MODULE_ID']
                . ' | callback: ' . ($callback !== '' ? $callback : '(не указан)')
                . ' | sort: ' . (int)$row['SORT'];
        }

        ksort($byModule);

        $documents = array();
        foreach ($byModule as $moduleId => $lines) {
            $documents[] = array(
                'source_id' => 'event:' . $moduleId,
                'title' => 'События модуля ' . $moduleId . ' (' . count($lines) . ')',
                'content' => 'Зарегистрированные обработчики событий модуля ' . $moduleId . ":\n" . implode("\n", $lines),
            );
        }

        return $documents;
    }

    // ---------- helpers ----------

    /** @return string[] абсолютные пути в стабильном порядке, с лимитом на группу */
    private static function collectFiles(string $dir, array $extensions, int $maxFileSize): array
    {
        $files = array();

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $path = $file->getPathname();

                // Жёсткие исключения: чужой код и пользовательский контент не индексируем.
                if (str_contains($path, '/local/php_interface/')
                    || str_contains($path, '/upload/')
                    || str_contains($path, '/bitrix/backup/')) {
                    continue;
                }

                if (!in_array(strtolower($file->getExtension()), $extensions, true)) {
                    continue;
                }

                if ($file->getSize() > $maxFileSize) {
                    continue;
                }

                $files[] = $path;
            }
        } catch (\Throwable $e) {
            Logger::error('Индексация: ошибка обхода каталога ' . $dir . ': ' . $e->getMessage());
        }

        sort($files);

        if (count($files) > self::MAX_FILES_PER_GROUP) {
            self::$truncated = true;
            $files = array_slice($files, 0, self::MAX_FILES_PER_GROUP);
        }

        return $files;
    }

    /**
     * Режет файл на документы ~1500 символов (окно модели 512 токенов) с перехлёстом 200.
     *
     * @param string[] $files
     * @return array<int, array{source_id:string, title:string, content:string}>
     */
    private static function fileDocuments(string $sourceType, array $files): array
    {
        $documents = array();
        $documentRoot = Loader::getDocumentRoot();

        foreach ($files as $path) {
            $contents = @file_get_contents($path);
            if (!is_string($contents) || str_contains($contents, "\0")) {
                continue; // бинарные/недоступные файлы пропускаем
            }

            $contents = trim($contents);
            if ($contents === '') {
                continue;
            }

            $relative = str_replace($documentRoot, '', $path);
            $chunkNumber = 0;
            $length = mb_strlen($contents);

            for ($start = 0; $start < $length; $start += self::CHUNK_SIZE - self::CHUNK_OVERLAP) {
                $documents[] = array(
                    'source_id' => $sourceType . ':' . $relative . '#' . $chunkNumber,
                    'title' => $relative . ($chunkNumber > 0 ? ' (часть ' . ($chunkNumber + 1) . ')' : ''),
                    'content' => mb_substr($contents, $start, self::CHUNK_SIZE),
                );
                $chunkNumber++;
            }
        }

        return $documents;
    }

    private static function cursorOffset(): int
    {
        return max(0, (int)self::getOption('index_offset', '0'));
    }

    private static function getOption(string $name, string $default): string
    {
        return (string)Option::get(Config::MODULE_ID, $name, $default);
    }

    private static function setOption(string $name, string $value): void
    {
        Option::set(Config::MODULE_ID, $name, $value);
    }
}
