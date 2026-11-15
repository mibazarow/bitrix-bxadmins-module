<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Loader;

/**
 * Создание торгового предложения для товара.
 * Свойства передаются объектом {КОД_СВОЙСТВА: значение}.
 */
class CreateOfferTool extends AbstractChangeTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'create_offer';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_offer',
            'description' => 'Создаёт торговое предложение (SKU) для товара. Свойства — объектом {КОД: значение}.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'product_id' => array('type' => 'integer', 'description' => 'ID товара-родителя'),
                    'name' => array('type' => 'string', 'description' => 'Название предложения'),
                    'code' => array('type' => 'string', 'description' => 'Символьный код'),
                    'active' => array('type' => 'boolean', 'description' => 'Активно (по умолчанию да)'),
                    'sort' => array('type' => 'integer', 'description' => 'Сортировка'),
                    'properties' => array(
                        'type' => 'object',
                        'description' => 'Свойства предложения: {"COLOR": "Красный", "SIZE": "M"}',
                    ),
                ),
                'required' => array('product_id', 'name'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $productId = (int)($args['product_id'] ?? 0);
        $name = IblockElementFields::name($args['name'] ?? null);

        if ($productId <= 0) {
            throw new \InvalidArgumentException('Параметр product_id обязателен.');
        }

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен.');
        }

        $product = ElementTable::getList(array(
            'select' => array('ID', 'IBLOCK_ID', 'NAME'),
            'filter' => array('=ID' => $productId),
            'limit' => 1,
        ))->fetch();

        if (!$product) {
            throw new \InvalidArgumentException('Товар #' . $productId . ' не найден.');
        }

        $skuProperty = PropertyTable::getList(array(
            'select' => array('ID', 'IBLOCK_ID', 'CODE'),
            'filter' => array(
                '=USER_TYPE' => 'SKU',
                '=LINK_IBLOCK_ID' => (int)$product['IBLOCK_ID'],
            ),
            'limit' => 1,
        ))->fetch();

        if (!$skuProperty) {
            throw new \InvalidArgumentException('У товара нет инфоблока торговых предложений.');
        }

        $properties = is_array($args['properties'] ?? null) ? $args['properties'] : array();
        $propertyCodes = IblockElementFields::propertyMap((int)$skuProperty['IBLOCK_ID']);

        $values = array();
        $lines = array();

        foreach ($properties as $code => $value) {
            $code = strtoupper(trim((string)$code));
            if (!isset($propertyCodes[$code])) {
                throw new \InvalidArgumentException('У торговых предложений нет свойства ' . $code . '.');
            }

            $values[$propertyCodes[$code]] = (string)$value;
            $lines[] = $code . ': ' . (string)$value;
        }

        $payload = array(
            'iblock_id' => (int)$skuProperty['IBLOCK_ID'],
            'product_id' => $productId,
            'sku_property_id' => (int)$skuProperty['ID'],
            'name' => $name,
            'code' => IblockElementFields::code($args['code'] ?? ''),
            'active' => IblockElementFields::active($args['active'] ?? true),
            'sort' => IblockElementFields::sort($args['sort'] ?? 500),
            'values' => $values,
        );

        return array(
            'summary' => 'Предложение «' . $name . '» для товара «' . $product['NAME'] . '»',
            'payload' => array('args' => $payload),
            'preview' => 'Будет создано торговое предложение «' . $name . '»'
                . ($lines !== array() ? ' (' . implode(', ', $lines) . ')' : ''),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен.');
        }

        $properties = array();
        foreach ((array)$args['values'] as $propertyId => $value) {
            $properties[(int)$propertyId] = array('VALUE' => $value);
        }

        // Связка с товаром-родителем: значение SKU-свойства = ID товара.
        $properties[(int)$args['sku_property_id']] = array('VALUE' => (string)$args['product_id']);

        // Легаси-исключение: добавление элемента с деревом свойств — только CIBlockElement.
        $element = new \CIBlockElement();
        $id = $element->Add(array(
            'IBLOCK_ID' => (int)$args['iblock_id'],
            'NAME' => (string)$args['name'],
            'CODE' => (string)$args['code'],
            'ACTIVE' => (string)$args['active'],
            'SORT' => (int)$args['sort'],
            'PROPERTY_VALUES' => $properties,
        ));

        if (!$id) {
            throw new \RuntimeException(IblockElementFields::legacyError($element, 'Не удалось создать предложение.'));
        }

        return array('offer_id' => (int)$id, 'product_id' => (int)$args['product_id']);
    }

    public static function rollback(array $payload): void
    {
        $offerId = (int)($payload['result']['offer_id'] ?? 0);
        if ($offerId <= 0) {
            return;
        }

        $element = new \CIBlockElement();
        if (!$element->Delete($offerId)) {
            throw new \RuntimeException(IblockElementFields::legacyError($element, 'Не удалось удалить предложение при откате.'));
        }
    }
}
