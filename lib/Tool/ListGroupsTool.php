<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\GroupTable;

/**
 * Группы пользователей.
 */
class ListGroupsTool extends AbstractTool
{
    public const DOMAIN = 'core';

    private const LIMIT = 200;

    public static function getName(): string
    {
        return 'list_groups';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_groups',
            'description' => 'Группы пользователей: ID, название, строковый код, сортировка, активность. Состав групп (пользователи) не возвращается. Максимум 200.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'offset' => array('type' => 'integer', 'description' => 'Пропустить N записей (для следующей страницы)'),
                ),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,groups:array} */
    public static function execute(array $args, array $context = array()): array
    {
        $rows = GroupTable::getList(array(
            'select' => array('ID', 'NAME', 'STRING_ID', 'C_SORT', 'ACTIVE'),
            'order' => array('C_SORT' => 'ASC', 'ID' => 'ASC'),
            'offset' => max(0, (int)($args['offset'] ?? 0)),
            'limit' => self::LIMIT + 1,
        ))->fetchAll();

        $truncated = count($rows) > self::LIMIT;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::LIMIT);
        }

        $groups = array();
        foreach ($rows as $row) {
            $groups[] = array(
                'id' => (int)$row['ID'],
                'name' => (string)$row['NAME'],
                'string_id' => (string)($row['STRING_ID'] ?? ''),
                'sort' => (int)$row['C_SORT'],
                'active' => $row['ACTIVE'] === 'Y' || $row['ACTIVE'] === true || $row['ACTIVE'] === 1,
            );
        }

        return array(
            'count' => count($groups),
            'truncated' => $truncated,
            'groups' => $groups,
        );
    }
}
