<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Sale\Location\LocationTable;

/** Местоположения (справочник). Только чтение — изменение дерева в админке. */
class ListLocationsTool extends AbstractTool
{
    public const DOMAIN = 'commerce';

    private const LIMIT = 100;

    public static function getName(): string
    {
        return 'list_locations';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_locations',
            'description' => 'Местоположения из справочника: ID, код, название, тип, родитель. Поиск по названию. Максимум 100.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'search' => array('type' => 'string', 'description' => 'Подстрока названия, например «Москва»'),
                    'parent_id' => array('type' => 'integer', 'description' => 'Только дочерние этого местоположения'),
                ),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,locations:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('sale')) {
            return array('error' => 'Модуль sale недоступен.');
        }

        $filter = array('=NAME.LANGUAGE_ID' => LANGUAGE_ID);

        if (($args['parent_id'] ?? null) !== null && (int)$args['parent_id'] >= 0) {
            $filter['=PARENT_ID'] = (int)$args['parent_id'];
        }

        $search = trim((string)($args['search'] ?? ''));
        if ($search !== '') {
            $filter['%NAME.NAME'] = $search;
        }

        $rows = LocationTable::getList(array(
            'select' => array('ID', 'CODE', 'TYPE_ID', 'NAME.NAME', 'PARENT_ID'),
            'filter' => $filter,
            'order' => array('LEFT_MARGIN' => 'ASC', 'ID' => 'ASC'),
            'limit' => self::LIMIT + 1,
        ))->fetchAll();

        $truncated = count($rows) > self::LIMIT;
        $locations = array();

        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            $locations[] = array(
                'id' => (int)$row['ID'],
                'code' => (string)$row['CODE'],
                'name' => (string)($row['SALE_LOCATION_LOCATION_NAME_NAME'] ?? ''),
                'type_id' => (int)$row['TYPE_ID'],
                'parent_id' => (int)($row['PARENT_ID'] ?? 0),
            );
        }

        return array('count' => count($locations), 'truncated' => $truncated, 'locations' => $locations);
    }
}
