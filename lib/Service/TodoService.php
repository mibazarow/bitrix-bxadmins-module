<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Mibazarow\Bxadmins\Model\TodoTable;

/** Persistent checklist for long agent tasks, scoped to a chat. */
final class TodoService
{
    private static bool $schemaReady = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        self::$schemaReady = true;

        try {
            $connection = Application::getConnection();
            $table = TodoTable::getTableName();

            if (!$connection->isTableExists($table)) {
                $connection->queryExecute(
                    "CREATE TABLE IF NOT EXISTS {$table} (
                        ID INT NOT NULL AUTO_INCREMENT,
                        CHAT_ID INT NOT NULL,
                        USER_ID INT NOT NULL DEFAULT 0,
                        TEXT VARCHAR(500) NOT NULL,
                        STATUS VARCHAR(20) NOT NULL DEFAULT 'pending',
                        SORT INT NOT NULL DEFAULT 100,
                        CREATED_AT DATETIME NOT NULL,
                        UPDATED_AT DATETIME NOT NULL,
                        PRIMARY KEY (ID),
                        KEY IX_MIBAZAROW_BXADMINS_TODO_CHAT (CHAT_ID, SORT, ID)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );
            }
        } catch (\Throwable $e) {
            Logger::error('Todo schema migration failed: ' . $e->getMessage());
        }
    }

    /** Replaces the checklist for a chat; items may be strings or {text,status}. */
    public static function replace(int $chatId, int $userId, array $items): array
    {
        self::ensureSchema();

        if ($chatId <= 0) {
            throw new \InvalidArgumentException('chat_id is required.');
        }

        TodoTable::deleteByFilter(array('=CHAT_ID' => $chatId));

        $sort = 0;
        foreach ($items as $item) {
            $text = is_array($item) ? trim((string)($item['text'] ?? '')) : trim((string)$item);
            if ($text === '') {
                continue;
            }

            $status = is_array($item) ? strtolower(trim((string)($item['status'] ?? 'pending'))) : 'pending';
            if (!in_array($status, array('pending', 'in_progress', 'done', 'cancelled'), true)) {
                $status = 'pending';
            }

            $sort += 10;
            TodoTable::add(array(
                'CHAT_ID' => $chatId,
                'USER_ID' => $userId,
                'TEXT' => mb_substr($text, 0, 500),
                'STATUS' => $status,
                'SORT' => $sort,
                'CREATED_AT' => new DateTime(),
                'UPDATED_AT' => new DateTime(),
            ));
        }

        return self::list($chatId);
    }

    /** @return array<int,array{id:int,text:string,status:string}> */
    public static function list(int $chatId): array
    {
        self::ensureSchema();

        $rows = array();

        try {
            foreach (TodoTable::getList(array(
                'filter' => array('=CHAT_ID' => $chatId),
                'order' => array('SORT' => 'ASC', 'ID' => 'ASC'),
                'limit' => 100,
            ))->fetchAll() as $row) {
                $rows[] = array(
                    'id' => (int)$row['ID'],
                    'text' => (string)$row['TEXT'],
                    'status' => (string)$row['STATUS'],
                );
            }
        } catch (\Throwable $e) {
            return array();
        }

        return $rows;
    }

    public static function clear(int $chatId): void
    {
        self::ensureSchema();
        TodoTable::deleteByFilter(array('=CHAT_ID' => $chatId));
    }

    /** Compact checklist for the system prompt. */
    public static function promptText(int $chatId): string
    {
        if ($chatId <= 0) {
            return '';
        }

        $items = self::list($chatId);
        if ($items === array()) {
            return '';
        }

        $mark = array('pending' => '[ ]', 'in_progress' => '[~]', 'done' => '[x]', 'cancelled' => '[-]');
        $lines = array('CURRENT CHECKLIST (keep it updated with todo_write):');

        foreach ($items as $item) {
            $lines[] = ($mark[$item['status']] ?? '[ ]') . ' ' . $item['text'];
        }

        return implode("\n", $lines);
    }
}
