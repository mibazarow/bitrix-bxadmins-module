<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Iblock\IblockTable;

/**
 * Список инфоблоков сайта.
 */
class ListIblocksTool extends AbstractTool
{
    public const DOMAIN = 'content';

    private const LIMIT = 200;

    public static function getName(): string
    {
        return 'list_iblocks';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_iblocks',
            'description' => 'Список инфоблоков сайта: ID, код, название, тип инфоблока, активность, сортировка. Опционально active_only=true — только активные.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'offset' => array('type' => 'integer', 'description' => 'Пропустить N записей (для следующей страницы)'),
                    'active_only' => array(
                        'type' => 'boolean',
                        'description' => 'Возвращать только активные инфоблоки.',
                    ),
                ),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,iblocks:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('iblock')) {
            return array('error' => 'Модуль iblock недоступен на этом сайте.');
        }

        $filter = array();
        if (!empty($args['active_only'])) {
            $filter['=ACTIVE'] = 'Y';
        }

        $rows = IblockTable::getList(array(
            'select' => array('ID', 'CODE', 'NAME', 'IBLOCK_TYPE_ID', 'ACTIVE', 'SORT'),
            'filter' => $filter,
            'order' => array('SORT' => 'ASC', 'ID' => 'ASC'),
            'offset' => max(0, (int)($args['offset'] ?? 0)),
            'limit' => self::LIMIT + 1,
        ))->fetchAll();

        $truncated = count($rows) > self::LIMIT;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::LIMIT);
        }

        $iblocks = array();
        foreach ($rows as $row) {
            $iblocks[] = array(
                'id' => (int)$row['ID'],
                'code' => (string)($row['CODE'] ?? ''),
                'name' => (string)$row['NAME'],
                'iblock_type_id' => (string)($row['IBLOCK_TYPE_ID'] ?? ''),
                'active' => $row['ACTIVE'] === 'Y',
                'sort' => (int)$row['SORT'],
            );
        }

        return array(
            'count' => count($iblocks),
            'truncated' => $truncated,
            'iblocks' => $iblocks,
        );
    }
}
