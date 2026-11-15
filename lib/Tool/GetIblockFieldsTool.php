<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\PropertyTable;

/**
 * Поля элементов и свойства инфоблока.
 */
class GetIblockFieldsTool extends AbstractTool
{
    public const DOMAIN = 'content';

    private const LIMIT = 200;

    public static function getName(): string
    {
        return 'get_iblock_fields';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'get_iblock_fields',
            'description' => 'Поля инфоблока по его ID: стандартные поля элементов и пользовательские свойства (ID, код, название, тип свойства, множественное, обязательное).',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'iblock_id' => array(
                        'type' => 'integer',
                        'description' => 'Числовой ID инфоблока.',
                    ),
                ),
                'required' => array('iblock_id'),
            ),
        );
    }

    /** @return array<string,mixed>|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('iblock')) {
            return array('error' => 'Модуль iblock недоступен на этом сайте.');
        }

        $iblockId = (int)($args['iblock_id'] ?? 0);
        if ($iblockId <= 0) {
            return array('error' => 'Некорректный iblock_id: нужен положительный integer.');
        }

        $iblock = IblockTable::getById($iblockId)->fetch();
        if (!$iblock) {
            return array('error' => 'Инфоблок с ID ' . $iblockId . ' не найден.');
        }

        $properties = array();
        $rows = PropertyTable::getList(array(
            'select' => array('ID', 'CODE', 'NAME', 'PROPERTY_TYPE', 'MULTIPLE', 'IS_REQUIRED'),
            'filter' => array('=IBLOCK_ID' => $iblockId),
            'order' => array('SORT' => 'ASC', 'ID' => 'ASC'),
            'limit' => self::LIMIT,
        ))->fetchAll();

        foreach ($rows as $row) {
            $properties[] = array(
                'id' => (int)$row['ID'],
                'code' => (string)($row['CODE'] ?? ''),
                'name' => (string)$row['NAME'],
                'property_type' => (string)$row['PROPERTY_TYPE'],
                'multiple' => $row['MULTIPLE'] === 'Y' || $row['MULTIPLE'] === true,
                'is_required' => $row['IS_REQUIRED'] === 'Y' || $row['IS_REQUIRED'] === true,
            );
        }

        return array(
            'iblock_id' => (int)$iblock['ID'],
            'iblock_name' => (string)$iblock['NAME'],
            'standard_fields' => array('ID', 'NAME', 'CODE', 'ACTIVE', 'SORT', 'PREVIEW_TEXT', 'DETAIL_TEXT'),
            'properties' => $properties,
            'properties_count' => count($properties),
        );
    }
}
