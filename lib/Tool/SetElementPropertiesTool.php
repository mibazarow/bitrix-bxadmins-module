<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Loader;

/**
 * Запись свойств элемента инфоблока по коду свойства.
 * Значения списков можно передавать текстом или ID варианта.
 */
class SetElementPropertiesTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'set_element_properties';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'set_element_properties',
            'description' => 'Записывает свойства элемента инфоблока по коду: {"COUNTRY": "Россия", "COLOR": "Красный"}. Множественное свойство — массивом.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'element_id' => array('type' => 'integer', 'description' => 'ID элемента'),
                    'properties' => array(
                        'type' => 'object',
                        'description' => 'Свойства: {"КОД": "значение"} или {"КОД": ["значение1", "значение2"]}',
                    ),
                ),
                'required' => array('element_id', 'properties'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $elementId = (int)($args['element_id'] ?? 0);
        $properties = is_array($args['properties'] ?? null) ? $args['properties'] : array();

        if ($elementId <= 0) {
            throw new \InvalidArgumentException('Параметр element_id обязателен.');
        }

        if ($properties === array()) {
            throw new \InvalidArgumentException('Не передано ни одного свойства.');
        }

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен.');
        }

        $element = ElementTable::getList(array(
            'select' => array('ID', 'NAME', 'IBLOCK_ID'),
            'filter' => array('=ID' => $elementId),
            'limit' => 1,
        ))->fetch();

        if (!$element) {
            throw new \InvalidArgumentException('Элемент #' . $elementId . ' не найден.');
        }

        // Разбор кодов и значений — общий с create/update элемента.
        $prepared = IblockElementFields::prepareProperties((int)$element['IBLOCK_ID'], $properties);
        $values = $prepared['values'];
        $lines = $prepared['lines'];

        // Сохраняем текущие значения затронутых свойств — это основа отката.
        $before = IblockElementFields::readPropertyValues($elementId, (int)$element['IBLOCK_ID'], array_keys($values));

        return array(
            'summary' => 'Свойства элемента #' . $elementId . ' «' . $element['NAME'] . '»',
            'payload' => array(
                'args' => array('element_id' => $elementId, 'values' => $values),
                'before' => array('iblock_id' => (int)$element['IBLOCK_ID'], 'values' => $before),
            ),
            'preview' => implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $elementId = (int)($args['element_id'] ?? 0);

        $element = new \CIBlockElement();
        $ok = $element->SetPropertyValuesEx($elementId, false, (array)$args['values']);

        if ($ok === false) {
            throw new \RuntimeException(\Mibazarow\Bxadmins\Service\LegacyError::message($element, 'Не удалось записать свойства.'));
        }

        return array('element_id' => $elementId, 'updated' => count((array)$args['values']));
    }

    public static function rollback(array $payload): void
    {
        $elementId = (int)($payload['args']['element_id'] ?? 0);
        $before = (array)($payload['before']['values'] ?? array());

        if ($elementId <= 0 || $before === array()) {
            return;
        }

        $element = new \CIBlockElement();
        $element->SetPropertyValuesEx($elementId, (int)($payload['before']['iblock_id'] ?? 0), $before);
    }
}
