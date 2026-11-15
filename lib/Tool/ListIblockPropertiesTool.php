<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Loader;

/** Свойства инфоблока (описания, без значений). */
class ListIblockPropertiesTool extends AbstractTool
{
    public const DOMAIN = 'content';

    private const LIMIT = 200;

    public static function getName(): string
    {
        return 'list_iblock_properties';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_iblock_properties',
            'description' => 'Свойства инфоблока: код, название, тип, множественность, обязательность. Максимум 200.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'iblock_id' => array('type' => 'integer', 'description' => 'ID инфоблока'),
                ),
                'required' => array('iblock_id'),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,properties:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $iblockId = (int)($args['iblock_id'] ?? 0);
        if ($iblockId <= 0) {
            return array('error' => 'Параметр iblock_id обязателен.');
        }

        if (!Loader::includeModule('iblock')) {
            return array('error' => 'Модуль iblock недоступен.');
        }

        $rows = PropertyTable::getList(array(
            'select' => array('ID', 'CODE', 'NAME', 'PROPERTY_TYPE', 'MULTIPLE', 'IS_REQUIRED', 'SORT', 'USER_TYPE'),
            'filter' => array('=IBLOCK_ID' => $iblockId),
            'order' => array('SORT' => 'ASC', 'ID' => 'ASC'),
            'limit' => self::LIMIT + 1,
        ))->fetchAll();

        $truncated = count($rows) > self::LIMIT;
        $properties = array();

        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            $properties[] = array(
                'id' => (int)$row['ID'],
                'code' => (string)($row['CODE'] ?? ''),
                'name' => (string)$row['NAME'],
                'type' => (string)$row['PROPERTY_TYPE'],
                'user_type' => (string)($row['USER_TYPE'] ?? ''),
                'multiple' => (string)$row['MULTIPLE'] === 'Y',
                'required' => (string)$row['IS_REQUIRED'] === 'Y',
                'sort' => (int)$row['SORT'],
            );
        }

        return array('count' => count($properties), 'truncated' => $truncated, 'properties' => $properties);
    }
}
