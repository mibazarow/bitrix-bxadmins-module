<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Type\DateTime;
use Mibazarow\Bxadmins\Model\MemoryTable;

/**
 * Долговременная память: пишется только по прямой просьбе пользователя
 * («запомни…»), живёт в БД и не зависит от чатов.
 */
final class MemoryService
{
    private const MAX_CONTENT = 4000;
    private const MAX_TITLE = 150;
    private const MAX_ENTRIES = 100;

    /** @return array<int,array{id:int,title:string,content:string,updated_at:mixed}> */
    public static function all(int $limit = 30): array
    {
        $limit = max(1, min(self::MAX_ENTRIES, $limit));
        $rows = array();

        try {
            foreach (MemoryTable::getList(array(
                'select' => array('ID', 'TITLE', 'CONTENT', 'UPDATED_AT'),
                'order' => array('UPDATED_AT' => 'DESC', 'ID' => 'DESC'),
                'limit' => $limit,
            ))->fetchAll() as $row) {
                $rows[] = array(
                    'id' => (int)$row['ID'],
                    'title' => (string)$row['TITLE'],
                    'content' => (string)$row['CONTENT'],
                    'updated_at' => $row['UPDATED_AT'] ?? null,
                );
            }
        } catch (\Throwable $e) {
            return array();
        }

        return $rows;
    }

    /** Сохраняет факт; одинаковый заголовок обновляет запись, а не плодит копии. */
    public static function save(string $title, string $content, int $userId = 0): int
    {
        $content = trim(str_replace("\0", '', $content));
        if ($content === '') {
            throw new \InvalidArgumentException('Нечего запоминать: пустой текст.');
        }

        $content = mb_substr($content, 0, self::MAX_CONTENT);

        $title = trim(str_replace("\0", '', $title));
        if ($title === '') {
            $title = mb_substr(preg_replace('/\s+/', ' ', $content) ?? $content, 0, 60);
        }
        $title = mb_substr($title, 0, self::MAX_TITLE);

        $existing = MemoryTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=TITLE' => $title),
            'limit' => 1,
        ))->fetch();

        if ($existing) {
            $result = MemoryTable::update((int)$existing['ID'], array(
                'CONTENT' => $content,
                'USER_ID' => $userId,
                'UPDATED_AT' => new DateTime(),
            ));

            if (!$result->isSuccess()) {
                throw new \RuntimeException('Не удалось обновить память.');
            }

            return (int)$existing['ID'];
        }

        $result = MemoryTable::add(array(
            'USER_ID' => $userId,
            'TITLE' => $title,
            'CONTENT' => $content,
            'CREATED_AT' => new DateTime(),
            'UPDATED_AT' => new DateTime(),
        ));

        if (!$result->isSuccess()) {
            throw new \RuntimeException('Не удалось сохранить в память.');
        }

        return (int)$result->getId();
    }

    /** Удаляет по id или точному заголовку. Возвращает число удалённых записей. */
    public static function forget(string $query): int
    {
        $query = trim($query);
        if ($query === '') {
            return 0;
        }

        if (ctype_digit($query)) {
            $id = (int)$query;
            if (MemoryTable::getById($id)->fetch()) {
                MemoryTable::delete($id);

                return 1;
            }
        }

        $rows = MemoryTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=TITLE' => $query),
        ))->fetchAll();

        foreach ($rows as $row) {
            MemoryTable::delete((int)$row['ID']);
        }

        return count($rows);
    }
}
