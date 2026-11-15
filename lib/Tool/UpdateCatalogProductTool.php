<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Loader;

/** Товарные поля каталога: активность, количество, вес, габариты, НДС, единица. */
class UpdateCatalogProductTool extends AbstractChangeTool
{
    public const DOMAIN = 'commerce';

    /** Поля, которые правит инструмент: catalog => подпись. */
    private const FIELDS = array(
        'QUANTITY' => 'Количество',
        'WEIGHT' => 'Вес',
        'WIDTH' => 'Ширина',
        'LENGTH' => 'Длина',
        'HEIGHT' => 'Высота',
        'VAT_ID' => 'НДС',
        'MEASURE' => 'Единица измерения',
        'PURCHASING_PRICE' => 'Закупочная цена',
        'QUANTITY_TRACE' => 'Учёт количества',
        'CAN_BUY_ZERO' => 'Продажа при нуле',
    );

    public static function getName(): string
    {
        return 'update_catalog_product';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_catalog_product',
            'description' => 'Меняет товарные поля: количество, вес, габариты, НДС, единицу измерения, закупочную цену, учёт остатков. Цену продажи меняет update_product_price.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'product_id' => array('type' => 'integer', 'description' => 'ID товара'),
                    'quantity' => array('type' => 'number', 'description' => 'Количество на складе (общее)'),
                    'weight' => array('type' => 'number', 'description' => 'Вес в граммах'),
                    'width' => array('type' => 'number', 'description' => 'Ширина, мм'),
                    'length' => array('type' => 'number', 'description' => 'Длина, мм'),
                    'height' => array('type' => 'number', 'description' => 'Высота, мм'),
                    'vat_id' => array('type' => 'integer', 'description' => 'ID ставки НДС (0 — без НДС)'),
                    'measure' => array('type' => 'integer', 'description' => 'ID единицы измерения'),
                    'purchasing_price' => array('type' => 'number', 'description' => 'Закупочная цена'),
                    'quantity_trace' => array('type' => 'boolean', 'description' => 'Вести учёт количества'),
                    'can_buy_zero' => array('type' => 'boolean', 'description' => 'Разрешить покупку при нуле'),
                ),
                'required' => array('product_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $productId = (int)($args['product_id'] ?? 0);
        if ($productId <= 0) {
            throw new \InvalidArgumentException('Параметр product_id обязателен.');
        }

        if (!Loader::includeModule('catalog')) {
            throw new \RuntimeException('Модуль catalog недоступен.');
        }

        $product = ProductTable::getList(array(
            'select' => array_merge(array('ID'), array_keys(self::FIELDS)),
            'filter' => array('=ID' => $productId),
            'limit' => 1,
        ))->fetch();

        if (!$product) {
            throw new \InvalidArgumentException('Товар #' . $productId . ' не найден в каталоге.');
        }

        $map = array(
            'quantity' => 'QUANTITY',
            'weight' => 'WEIGHT',
            'width' => 'WIDTH',
            'length' => 'LENGTH',
            'height' => 'HEIGHT',
            'vat_id' => 'VAT_ID',
            'measure' => 'MEASURE',
            'purchasing_price' => 'PURCHASING_PRICE',
            'quantity_trace' => 'QUANTITY_TRACE',
            'can_buy_zero' => 'CAN_BUY_ZERO',
        );

        $fields = array();
        $before = array();
        $lines = array();

        foreach ($map as $argName => $field) {
            if (!array_key_exists($argName, $args)) {
                continue;
            }

            $value = $args[$argName];

            if (in_array($field, array('QUANTITY_TRACE', 'CAN_BUY_ZERO'), true)) {
                $value = ((bool)$value) ? 'Y' : 'N';
            } elseif (in_array($field, array('VAT_ID', 'MEASURE'), true)) {
                $value = (int)$value;
            } else {
                $value = (float)$value;
            }

            $old = $product[$field] ?? null;
            if ((string)$old === (string)$value) {
                continue;
            }

            $fields[$field] = $value;
            $before[$field] = $old;
            $lines[] = self::FIELDS[$field] . ': ' . (string)$old . ' → ' . (string)$value;
        }

        if ($fields === array()) {
            throw new \InvalidArgumentException('Изменений нет: переданные значения совпадают с текущими.');
        }

        return array(
            'summary' => 'Товар #' . $productId . ': ' . implode(', ', array_keys($fields)),
            'payload' => array(
                'args' => array('product_id' => $productId, 'fields' => $fields),
                'before' => array('fields' => $before),
            ),
            'preview' => "Товар #" . $productId . "\n" . implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $productId = (int)($payload['args']['product_id'] ?? 0);
        $fields = (array)($payload['args']['fields'] ?? array());

        if ($productId <= 0 || $fields === array()) {
            throw new \InvalidArgumentException('Нет данных для изменения товара.');
        }

        $result = ProductTable::update($productId, $fields);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        return array('product_id' => $productId, 'updated' => array_keys($fields));
    }

    public static function rollback(array $payload): void
    {
        $productId = (int)($payload['args']['product_id'] ?? 0);
        $before = (array)($payload['before']['fields'] ?? array());

        if ($productId <= 0 || $before === array()) {
            return;
        }

        $result = ProductTable::update($productId, $before);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }
    }
}
