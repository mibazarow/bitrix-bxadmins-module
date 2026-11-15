<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Mibazarow\Bxadmins\Config;
use Mibazarow\Bxadmins\Model\ChangeTable;
use Mibazarow\Bxadmins\Model\ChatTable;
use Mibazarow\Bxadmins\Tool\ChangeToolInterface;

/**
 * Жизненный цикл изменения: предложение → подтверждение → применение → откат, с SQL-дампом и снапшотами файлов.
 */
class ChangeService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_EXECUTED = 'executed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    private const PREVIEW_LIMIT = 20000;
    private const SUMMARY_LIMIT = 500;
    private const AUTO_RETRY_WINDOW_MINUTES = 10;
    private const AUTO_RETRY_MAX_FAILED = 1;

    /**
     * Изменения без собственного отката БД: перед ними нужен полный дамп.
     * Всё остальное откатывается rollback()-ом инструмента и git-точкой файлов.
     */
    private const SQL_DUMP_TOOLS = array(
        'import_csv_elements',
        'set_user_password',
        'run_php',
        'install_module',
        'uninstall_module',
        'restore_site',
    );

    private const ROW_FIELDS = array(
        'ID', 'CHAT_ID', 'TOOL', 'SUMMARY', 'PREVIEW', 'STATUS', 'BATCH_ID', 'RESULT', 'BACKUP_FILE', 'CREATED_AT', 'UPDATED_AT',
    );

    /** @var class-string<ChangeToolInterface>[] */
    /** Сохраняет изменение в статусе pending и возвращает его ID. */
    public static function propose(
        int $userId,
        int $chatId,
        string $domain,
        string $tool,
        string $summary,
        array $payload,
        string $preview,
        string $batchId = ''
    ): int {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Не удалось определить пользователя для изменения.');
        }

        if (!Config::isDomainAllowed($domain)) {
            throw new \InvalidArgumentException('Домен "' . $domain . '" отключён в настройках — изменение запрещено.');
        }

        if ($chatId > 0) {
            $chat = ChatTable::getById($chatId)->fetch();
            if (!$chat || (int)$chat['USER_ID'] !== $userId) {
                throw new \InvalidArgumentException('Чат не найден или недоступен.');
            }
        }

        self::handlerFor($tool);

        $summary = trim($summary);
        if ($summary === '') {
            throw new \InvalidArgumentException('Не удалось сформировать описание изменения.');
        }

        $result = ChangeTable::add(array(
            'CHAT_ID' => $chatId > 0 ? $chatId : null,
            'USER_ID' => $userId,
            'DOMAIN' => $domain,
            'TOOL' => $tool,
            'SUMMARY' => mb_substr($summary, 0, self::SUMMARY_LIMIT),
            'PREVIEW' => $preview !== '' ? mb_substr($preview, 0, self::PREVIEW_LIMIT) : null,
            'PAYLOAD' => Json::encode($payload),
            'STATUS' => self::STATUS_PENDING,
            'BATCH_ID' => $batchId !== '' ? mb_substr($batchId, 0, 64) : null,
            'CREATED_AT' => new DateTime(),
            'UPDATED_AT' => new DateTime(),
        ));

        if (!$result->isSuccess()) {
            throw new \RuntimeException('Не удалось сохранить изменение.');
        }

        return (int)$result->getId();
    }

    /** @return array{ok:bool,change_id:int,status:string,result?:array,backup_file?:string|null,error?:string} */
    public static function confirm(int $changeId, int $userId, bool $report = false): array
    {
        $change = self::loadOwned($changeId, $userId);

        if ((string)$change['STATUS'] !== self::STATUS_PENDING) {
            throw new \InvalidArgumentException('Изменение уже обработано: ' . (string)$change['STATUS'] . '.');
        }

        if (!Config::isDomainAllowed((string)$change['DOMAIN'])) {
            throw new \InvalidArgumentException('Домен "' . (string)$change['DOMAIN'] . '" отключён в настройках — изменение нельзя выполнить.');
        }

        $handler = self::handlerFor((string)$change['TOOL']);
        Modules::ensure();
        $payload = self::decodePayload((string)$change['PAYLOAD']);
        // Идентификатор нужен инструментам для снапшотов; в БД он появляется только после вставки строки.
        $payload['change_id'] = $changeId;
        // Владелец изменения — инструментам, работающим от его имени (откат, авторство).
        $payload['user_id'] = $userId;

        // Точка отката: сначала SQL-дамп, затем git-коммит всей файловой структуры.
        $backupFile = null;
        $needsDump = self::needsSqlDump((string)$change['TOOL'], $handler);

        if ($needsDump) {
            $lock = self::acquireDumpLock();

            try {
                // Служебные таблицы модуля в точку отката не входят (см. BackupService).
                $backup = BackupService::create(false);
                $backupFile = basename((string)$backup['file']);
            } catch (\Throwable $e) {
                Logger::error('Change #' . $changeId . ': не удалось создать SQL-дамп: ' . $e->getMessage());
                $message = 'Не удалось создать SQL-дамп — изменение не выполнено.';

                self::markFailed($changeId, $message);

                return array(
                    'ok' => false,
                    'change_id' => $changeId,
                    'status' => self::STATUS_FAILED,
                    'error' => $message,
                );
            } finally {
                self::releaseDumpLock($lock);
            }
        }

        $point = GitService::point(
            'BXADMIN-S: точка отката перед изменением #' . $changeId . ' — ' . (string)$change['SUMMARY']
        );
        // Инструменту точка нужна, чтобы сохранить её в собственной записи истории.
        $payload['point'] = $point;

        try {
            $result = $handler::apply($payload);
            $result = is_array($result) ? $result : array();
            // Точка отката нужна инструменту restore_site и видна в истории изменений.
            $result['point'] = $point;
        } catch (\Throwable $e) {
            Logger::error('Change #' . $changeId . ' apply error: ' . $e->getMessage(), array(
                'tool' => (string)$change['TOOL'],
            ));
            $message = self::safeError($e);

            self::updateRow($changeId, array(
                'STATUS' => self::STATUS_FAILED,
                'RESULT' => Json::encode(array('error' => $message, 'point' => $point)),
                'BACKUP_FILE' => $backupFile,
            ));

            if ($report) {
                self::report(
                    $change,
                    'Не удалось: ' . rtrim((string)$change['SUMMARY'], '.') . '. Причина: ' . $message,
                    true
                );
                self::enqueueAutoRetry($change, $e->getMessage(), $userId);
            }

            // Частично записанные файлы тоже попадают в git — иначе их не уберёт возврат к точке.
            GitService::point('BXADMIN-S: изменение #' . $changeId . ' завершилось ошибкой');

            return array(
                'ok' => false,
                'change_id' => $changeId,
                'status' => self::STATUS_FAILED,
                'backup_file' => $backupFile,
                'error' => $message,
            );
        }

        self::updateRow($changeId, array(
            'STATUS' => self::STATUS_EXECUTED,
            'RESULT' => Json::encode($result),
            'BACKUP_FILE' => $backupFile,
        ));

        // Фиксируем применённое состояние: созданные файлы должны быть в git,
        // иначе возврат к более ранней точке их не удалит.
        GitService::point('BXADMIN-S: изменение #' . $changeId . ' выполнено');

        if ($report) {
            self::report($change, self::doneText($change, $result));
        }

        return array(
            'ok' => true,
            'change_id' => $changeId,
            'status' => self::STATUS_EXECUTED,
            'result' => $result,
            'backup_file' => $backupFile,
        );
    }

    public static function reject(int $changeId, int $userId): void
    {
        $change = self::loadOwned($changeId, $userId);

        if ((string)$change['STATUS'] !== self::STATUS_PENDING) {
            throw new \InvalidArgumentException('Отклонить можно только ожидающее изменение.');
        }

        self::updateRow($changeId, array('STATUS' => self::STATUS_REJECTED));
    }

    /** @return array{ok:bool,change_id:int,status:string,error?:string} */
    public static function rollback(int $changeId, int $userId, bool $report = false): array
    {
        $change = self::loadOwned($changeId, $userId);

        if ((string)$change['STATUS'] !== self::STATUS_EXECUTED) {
            throw new \InvalidArgumentException('Откатить можно только выполненное изменение.');
        }

        $handler = self::handlerFor((string)$change['TOOL']);
        Modules::ensure();
        $payload = self::decodePayload((string)$change['PAYLOAD']);
        $payload['change_id'] = $changeId;
        $payload['result'] = self::decodePayload((string)($change['RESULT'] ?? ''));

        // Разрушительные операции (удаление инфоблока, раздела, темы, пользователя,
        // модуля) точечно не отменяются — возвращаем сайт к точке отката.
        if ($handler::rollbackNeedsRestore()) {
            try {
                $restore = SiteRestoreService::restore($changeId, $changeId, true);
            } catch (\Throwable $e) {
                Logger::error('Change #' . $changeId . ' restore rollback error: ' . $e->getMessage(), array(
                    'tool' => (string)$change['TOOL'],
                ));

                if ($report) {
                    self::report($change, 'Не удалось откатить: ' . rtrim((string)$change['SUMMARY'], '.') . '. Причина: ' . self::safeError($e), true);
                }

                return array(
                    'ok' => false,
                    'change_id' => $changeId,
                    'status' => self::STATUS_EXECUTED,
                    'error' => self::safeError($e),
                );
            }

            $result = self::decodePayload((string)($change['RESULT'] ?? ''));
            $result['rollback'] = array('mode' => 'site_restore') + $restore;

            self::updateRow($changeId, array(
                'STATUS' => self::STATUS_ROLLED_BACK,
                'RESULT' => Json::encode($result),
            ));

            if ($report) {
                self::report($change, 'Откат выполнен: ' . rtrim((string)$change['SUMMARY'], '.') . '.');
            }

            return array(
                'ok' => true,
                'change_id' => $changeId,
                'status' => self::STATUS_ROLLED_BACK,
                'restored_site' => true,
                'restore' => $restore,
            );
        }

        try {
            $handler::rollback($payload);
        } catch (\Throwable $e) {
            Logger::error('Change #' . $changeId . ' rollback error: ' . $e->getMessage(), array(
                'tool' => (string)$change['TOOL'],
            ));

            if ($report) {
                self::report($change, 'Не удалось откатить: ' . rtrim((string)$change['SUMMARY'], '.') . '. Причина: ' . self::safeError($e), true);
            }

            return array(
                'ok' => false,
                'change_id' => $changeId,
                'status' => self::STATUS_EXECUTED,
                'error' => self::safeError($e),
            );
        }

        self::updateRow($changeId, array('STATUS' => self::STATUS_ROLLED_BACK));

        if ($report) {
            self::report($change, 'Откат выполнен: ' . rtrim((string)$change['SUMMARY'], '.') . '.');
        }

        return array(
            'ok' => true,
            'change_id' => $changeId,
            'status' => self::STATUS_ROLLED_BACK,
        );
    }

    /** Короткий отчёт в чат: задача должна заканчиваться понятным результатом. */
    private static function doneText(array $change, array $result): string
    {
        $text = 'Готово: ' . rtrim((string)$change['SUMMARY'], '.');

        if (isset($result['updated'])) {
            $text .= '. Затронуто: ' . (int)$result['updated'];
        }

        return $text . '.';
    }

    private static function report(array $change, string $text, bool $isError = false): void
    {
        $chatId = (int)($change['CHAT_ID'] ?? 0);
        if ($chatId <= 0) {
            return;
        }

        try {
            ChatService::appendMessage($chatId, 'status', $text, '', '', '', $isError);
        } catch (\Throwable $e) {
            Logger::error('Change #' . (int)($change['ID'] ?? 0) . ' отчёт не записан: ' . $e->getMessage());
        }
    }

    /**
     * Изменения одной задачи: подтверждаются одной карточкой и одним действием.
     *
     * @param string[] $statuses
     * @return array<int,array>
     */
    private static function batchRows(string $batchId, int $userId, array $statuses, string $order = 'ASC'): array
    {
        if ($batchId === '') {
            return array();
        }

        $rows = ChangeTable::getList(array(
            'select' => self::ROW_FIELDS,
            'filter' => array(
                '=BATCH_ID' => $batchId,
                '=USER_ID' => $userId,
                '@STATUS' => $statuses,
            ),
            'order' => array('ID' => $order),
        ))->fetchAll();

        return array_map(static function (array $row): array {
            return self::mapRow($row);
        }, $rows);
    }

    /** Подтверждает все изменения пачки по порядку; при сбое откатывает уже применённые. */
    public static function confirmBatch(string $batchId, int $userId, bool $report = true): array
    {
        $rows = self::batchRows($batchId, $userId, array(self::STATUS_PENDING), 'ASC');
        if ($rows === array()) {
            throw new \InvalidArgumentException('Нет изменений, ожидающих подтверждения.');
        }

        $applied = array();
        $failed = '';

        foreach ($rows as $row) {
            try {
                $result = self::confirm((int)$row['id'], $userId, false);
                if (empty($result['ok'])) {
                    $failed = (string)($result['error'] ?? 'Изменение не выполнено.');
                    break;
                }
                $applied[] = (int)$row['id'];
            } catch (\Throwable $e) {
                $failed = self::safeError($e);
                break;
            }
        }

        $full = ChangeTable::getById((int)$rows[0]['id'])->fetch() ?: array();

        if ($failed !== '') {
            $rolled = 0;
            foreach (array_reverse($applied) as $appliedId) {
                try {
                    self::rollback($appliedId, $userId, false);
                    $rolled++;
                } catch (\Throwable $e) {
                    Logger::error('Batch rollback #' . $appliedId . ': ' . $e->getMessage());
                }
            }

            $text = 'Не удалось применить изменения: ' . $failed;
            if ($rolled > 0) {
                $text .= ' Уже применённые отменены (' . $rolled . ').';
            }

            if ($report && $full !== array()) {
                self::report($full, $text, true);
            }

            return array(
                'ok' => false,
                'error' => $failed,
                'applied' => count($applied),
                'rolled_back' => $rolled,
                'total' => count($rows),
            );
        }

        if ($report && $full !== array()) {
            self::report($full, self::batchDoneText($rows));
        }

        return array(
            'ok' => true,
            'done' => count($rows),
            'total' => count($rows),
            'batch_id' => $batchId,
        );
    }

    /** Отклоняет все ожидающие изменения пачки. */
    public static function rejectBatch(string $batchId, int $userId): int
    {
        $rows = self::batchRows($batchId, $userId, array(self::STATUS_PENDING), 'ASC');

        foreach ($rows as $row) {
            self::reject((int)$row['id'], $userId);
        }

        return count($rows);
    }

    /** Откатывает все выполненные изменения пачки в обратном порядке. */
    public static function rollbackBatch(string $batchId, int $userId, bool $report = true): array
    {
        $rows = self::batchRows($batchId, $userId, array(self::STATUS_EXECUTED), 'DESC');
        if ($rows === array()) {
            throw new \InvalidArgumentException('Нет выполненных изменений для отката.');
        }

        $rolled = 0;
        $failed = '';

        foreach ($rows as $row) {
            try {
                $result = self::rollback((int)$row['id'], $userId, false);
                if (empty($result['ok'])) {
                    $failed = (string)($result['error'] ?? 'Откат не выполнен.');
                    break;
                }
                $rolled++;

                // Возврат сайта к точке уже отменил более ранние изменения пачки.
                if (!empty($result['restored_site'])) {
                    break;
                }
            } catch (\Throwable $e) {
                $failed = self::safeError($e);
                break;
            }
        }

        $full = ChangeTable::getById((int)$rows[0]['id'])->fetch() ?: array();

        if ($report && $full !== array()) {
            if ($failed !== '') {
                self::report($full, 'Откат выполнен частично (' . $rolled . ' из ' . count($rows) . '): ' . $failed, true);
            } else {
                self::report($full, 'Откат выполнен: ' . $rolled . ' ' . self::changeWord($rolled) . '.');
            }
        }

        if ($failed !== '') {
            return array('ok' => false, 'error' => $failed, 'rolled_back' => $rolled, 'total' => count($rows));
        }

        return array('ok' => true, 'rolled_back' => $rolled, 'total' => count($rows), 'batch_id' => $batchId);
    }

    private static function batchDoneText(array $rows): string
    {
        $titles = array();

        foreach (array_slice($rows, 0, 3) as $row) {
            $titles[] = mb_substr(rtrim((string)$row['summary'], '.'), 0, 80);
        }

        $text = 'Готово: ' . count($rows) . ' ' . self::changeWord(count($rows));

        if ($titles !== array()) {
            $text .= ' — ' . implode(', ', $titles);
            if (count($rows) > 3) {
                $text .= ' и ещё ' . (count($rows) - 3);
            }
        }

        return $text . '.';
    }

    private static function changeWord(int $count): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return 'изменение';
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return 'изменения';
        }

        return 'изменений';
    }

    /** propose() + confirm(): используется в режиме bypass. */
    public static function applyNow(
        int $userId,
        int $chatId,
        string $domain,
        string $tool,
        string $summary,
        array $payload,
        string $preview,
        string $batchId = ''
    ): array {
        $changeId = self::propose($userId, $chatId, $domain, $tool, $summary, $payload, $preview, $batchId);
        // confirm() сам решает, нужен ли SQL-дамп (needsSqlDump у обработчика).
        $result = self::confirm($changeId, $userId, false);
        $result['executed'] = !empty($result['ok']);

        return $result;
    }

    /** Изменения чата для карточек в истории (свежие сначала). */
    public static function listForChat(int $chatId, int $userId): array
    {
        $chat = ChatTable::getById($chatId)->fetch();
        if (!$chat || (int)$chat['USER_ID'] !== $userId) {
            throw new \InvalidArgumentException('Чат не найден или недоступен.');
        }

        $rows = ChangeTable::getList(array(
            'select' => self::ROW_FIELDS,
            'filter' => array('=CHAT_ID' => $chatId, '=USER_ID' => $userId),
            'order' => array('ID' => 'DESC'),
            'limit' => 100,
        ))->fetchAll();

        return array_map(static function (array $row): array {
            return self::mapRow($row);
        }, $rows);
    }

    /** Последние изменения установки для страницы «Управление». */
    public static function recent(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        $rows = ChangeTable::getList(array(
            'select' => self::ROW_FIELDS,
            'order' => array('ID' => 'DESC'),
            'limit' => $limit,
        ))->fetchAll();

        return array_map(static function (array $row): array {
            return self::mapRow($row);
        }, $rows);
    }

    /**
     * Нужен ли полный SQL-дамп перед этим изменением.
     *
     * Политика irreversible (по умолчанию): дамп только для разрушительных
     * операций и инструментов без собственного отката БД. always — для всех,
     * off — ни для кого (аварийный режим).
     */
    private static function needsSqlDump(string $tool, string $handler): bool
    {
        $policy = Config::sqlDumpPolicy();

        if ($policy === 'off') {
            return false;
        }

        if ($policy === 'always' || $handler::needsSqlDump()) {
            return true;
        }

        return str_starts_with($tool, 'delete_')
            || in_array($tool, self::SQL_DUMP_TOOLS, true);
    }

    /**
     * Сериализует дампы: два одновременных подтверждения не должны запускать
     * два mysqldump по 160 МБ. Возвращает null, если файл блокировки недоступен.
     *
     * @return resource|null
     */
    private static function acquireDumpLock()
    {
        $path = BackupService::dirPath() . '/.dump.lock';
        $handle = @fopen($path, 'c');

        if ($handle === false) {
            Logger::error('Не удалось открыть lock-файл SQL-дампа: ' . $path);

            return null;
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            Logger::error('Не удалось взять lock SQL-дампа: ' . $path);

            return null;
        }

        return $handle;
    }

    /** @param resource|null $handle */
    private static function releaseDumpLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return class-string<ChangeToolInterface> */
    private static function handlerFor(string $tool): string
    {
        // Список обработчиков не дублируем: берём изменяющие инструменты из реестра.
        foreach (ToolRegistry::tools() as $handler) {
            if ($handler::getName() === $tool && is_subclass_of($handler, ChangeToolInterface::class)) {
                return $handler;
            }
        }

        throw new \InvalidArgumentException('Инструмент "' . $tool . '" не поддерживает изменения с подтверждением.');
    }

    private static function loadOwned(int $changeId, int $userId): array
    {
        if ($changeId <= 0) {
            throw new \InvalidArgumentException('Изменение не найдено или недоступно.');
        }

        $change = ChangeTable::getById($changeId)->fetch();
        if (!$change || (int)$change['USER_ID'] !== $userId) {
            throw new \InvalidArgumentException('Изменение не найдено или недоступно.');
        }

        return $change;
    }

    private static function markFailed(int $changeId, string $message): void
    {
        self::updateRow($changeId, array(
            'STATUS' => self::STATUS_FAILED,
            'RESULT' => Json::encode(array('error' => $message)),
        ));
    }

    private static function updateRow(int $changeId, array $fields): void
    {
        $fields['UPDATED_AT'] = new DateTime();

        $result = ChangeTable::update($changeId, $fields);
        if (!$result->isSuccess()) {
            Logger::error('Change #' . $changeId . ': не удалось обновить запись изменения.');
        }
    }

    private static function decodePayload(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return array();
        }

        try {
            $decoded = Json::decode($raw);
        } catch (\Throwable $e) {
            return array();
        }

        return is_array($decoded) ? $decoded : array();
    }

    private static function mapRow(array $row): array
    {
        return array(
            'id' => (int)$row['ID'],
            'tool' => (string)$row['TOOL'],
            'summary' => (string)$row['SUMMARY'],
            'preview' => (string)($row['PREVIEW'] ?? ''),
            'status' => (string)$row['STATUS'],
            'batch_id' => (string)($row['BATCH_ID'] ?? ''),
            'result' => self::decodePayload((string)($row['RESULT'] ?? '')),
            'backup_file' => (string)($row['BACKUP_FILE'] ?? ''),
            'chat_id' => (int)($row['CHAT_ID'] ?? 0),
            'created_at' => self::formatDate($row['CREATED_AT'] ?? null),
            'updated_at' => self::formatDate($row['UPDATED_AT'] ?? null),
        );
    }

    private static function formatDate($value): string
    {
        return $value instanceof DateTime ? $value->format('Y-m-d H:i:s') : (string)$value;
    }

    /**
     * Возвращает упавшее изменение агенту на доработку. Не больше одной попытки
     * в окне, иначе авто-ретрай зациклится на одной и той же ошибке.
     */
    private static function enqueueAutoRetry(array $change, string $error, int $userId): void
    {
        try {
            $chatId = (int)($change['CHAT_ID'] ?? 0);
            $changeId = (int)($change['ID'] ?? 0);

            if ($chatId <= 0 || $userId <= 0 || $changeId <= 0) {
                return;
            }

            $connection = \Bitrix\Main\Application::getConnection();
            $since = date('Y-m-d H:i:s', time() - self::AUTO_RETRY_WINDOW_MINUTES * 60);
            $row = $connection->query(
                'SELECT COUNT(*) AS CNT FROM b_mibazarow_bxadmins_change'
                . ' WHERE CHAT_ID = ' . $chatId
                . " AND STATUS = '" . self::STATUS_FAILED . "'"
                . " AND UPDATED_AT >= '" . $since . "'"
            )->fetch();

            if ((int)($row['CNT'] ?? 0) > self::AUTO_RETRY_MAX_FAILED) {
                return;
            }

            $prompt = 'Автоматический ретрай: изменение #' . $changeId . ' не применилось.' . "\n"
                . 'Ошибка: ' . $error . "\n"
                . 'Инструмент: ' . (string)($change['TOOL'] ?? '') . "\n"
                . 'Что было задумано: ' . (string)($change['SUMMARY'] ?? '') . "\n\n"
                . 'Разберись в причине. Если сомневаешься в API — проверь через MCP (liveApiGetClassMethods, '
                . 'liveApiGetFunctionCode, searchDocs). Исправь код и предложи новое изменение. '
                . 'Не утверждай, что готово, пока изменение не получит статус executed. '
                . 'Если причина непонятна — остановись и объясни пользователю, что нужно уточнить.';

            TaskService::enqueue($chatId, $userId, $prompt, false);
        } catch (\Throwable $e) {
            Logger::error(
                'Change #' . (int)($change['ID'] ?? 0) . ': авто-ретрай не поставлен: ' . $e->getMessage()
            );
        }
    }

    private static function safeError(\Throwable $e): string
    {
        if ($e instanceof \InvalidArgumentException || $e instanceof \RuntimeException) {
            $message = trim($e->getMessage());

            return $message !== '' ? $message : 'Операция не выполнена.';
        }

        return 'Операция не выполнена. Подробности — в журнале модуля.';
    }
}
