<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Loader;

/** Элемент инфоблока с полями и свойствами. */
class GetIblockElementTool extends AbstractTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'get_iblock_element';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'get_iblock_element',
            'description' => 'Элемент инфоблока: основные поля и значения свойств.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'element_id' => array('type' => 'integer', 'description' => 'ID элемента'),
                ),
                'required' => array('element_id'),
            ),
        );
    }

    /** @return array<string,mixed>|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $elementId = (int)($args['element_id'] ?? 0);
        if ($elementId <= 0) {
            return array('error' => 'Параметр element_id обязателен.');
        }

        if (!Loader::includeModule('iblock')) {
            return array('error' => 'Модуль iblock недоступен.');
        }

        $element = ElementTable::getList(array(
            'select' => array('ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'CODE', 'NAME', 'ACTIVE', 'SORT', 'PREVIEW_TEXT', 'DETAIL_TEXT', 'PREVIEW_TEXT_TYPE', 'DETAIL_TEXT_TYPE'),
            'filter' => array('=ID' => $elementId),
            'limit' => 1,
        ))->fetch();

        if (!$element) {
            return array('error' => 'Элемент #' . $elementId . ' не найден.');
        }

        $properties = array();
        $propertyRows = PropertyTable::getList(array(
            'select' => array('ID', 'CODE', 'NAME', 'PROPERTY_TYPE'),
            'filter' => array('=IBLOCK_ID' => (int)$element['IBLOCK_ID']),
        ))->fetchAll();

        $values = \CIBlockElement::GetProperty((int)$element['IBLOCK_ID'], $elementId, array('sort' => 'asc'), false);
        $byId = array();
        foreach ($propertyRows as $property) {
            $byId[(int)$property['ID']] = (string)$property['CODE'];
        }

        // Легаси-исключение: D7-API для значений свойств элемента нет, только CIBlockElement::GetProperty.
        while ($value = $values->Fetch()) {
            $code = $byId[(int)$value['ID']] ?? ('PROP_' . (int)$value['ID']);
            if (!isset($properties[$code])) {
                $properties[$code] = array();
            }
            if ($value['VALUE'] !== null && $value['VALUE'] !== '') {
                $properties[$code][] = $value['VALUE'];
            }
        }

        return array(
            'id' => (int)$element['ID'],
            'iblock_id' => (int)$element['IBLOCK_ID'],
            'section_id' => (int)($element['IBLOCK_SECTION_ID'] ?? 0),
            'code' => (string)($element['CODE'] ?? ''),
            'name' => (string)$element['NAME'],
            'active' => (string)$element['ACTIVE'] === 'Y',
            'sort' => (int)$element['SORT'],
            'preview_text' => (string)($element['PREVIEW_TEXT'] ?? ''),
            'detail_text' => (string)($element['DETAIL_TEXT'] ?? ''),
            'properties' => $properties,
        );
    }
}
