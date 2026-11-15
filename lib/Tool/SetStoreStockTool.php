<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Catalog\StoreProductTable;
use Bitrix\Main\Loader;

/** Остаток товара на конкретном складе. */
class SetStoreStockTool extends AbstractChangeTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'set_store_stock';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'set_store_stock',
            'description' => 'Ставит количество товара на складе. Список складов — list_stores.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'product_id' => array('type' => 'integer', 'description' => 'ID товара'),
                    'store_id' => array('type' => 'integer', 'description' => 'ID склада'),
                    'amount' => array('type' => 'number', 'description' => 'Количество на складе'),
                ),
                'required' => array('product_id', 'store_id', 'amount'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $productId = (int)($args['product_id'] ?? 0);
        $storeId = (int)($args['store_id'] ?? 0);
        $amount = (float)($args['amount'] ?? 0);

        if ($productId <= 0 || $storeId <= 0 || $amount < 0) {
            throw new \InvalidArgumentException('Нужны product_id, store_id и неотрицательное amount.');
        }

        if (!Loader::includeModule('catalog')) {
            throw new \RuntimeException('Модуль catalog недоступен.');
        }

        $store = \Bitrix\Catalog\StoreTable::getList(array(
            'select' => array('ID', 'TITLE'),
            'filter' => array('=ID' => $storeId),
            'limit' => 1,
        ))->fetch();

        if (!$store) {
            throw new \InvalidArgumentException('Склад #' . $storeId . ' не найден.');
        }

        $row = StoreProductTable::getList(array(
            'select' => array('ID', 'AMOUNT', 'QUANTITY_RESERVED'),
            'filter' => array('=PRODUCT_ID' => $productId, '=STORE_ID' => $storeId),
            'limit' => 1,
        ))->fetch();

        return array(
            'summary' => 'Остаток товара #' . $productId . ' на складе «' . $store['TITLE'] . '»',
            'payload' => array(
                'args' => array(
                    'product_id' => $productId,
                    'store_id' => $storeId,
                    'amount' => $amount,
                    'row_id' => (int)($row['ID'] ?? 0),
                ),
                'before' => array(
                    'exists' => $row !== false,
                    'amount' => (float)($row['AMOUNT'] ?? 0),
                    'reserved' => (float)($row['QUANTITY_RESERVED'] ?? 0),
                ),
            ),
            'preview' => 'Товар #' . $productId . ', склад «' . $store['TITLE'] . '»: '
                . ($row ? (float)$row['AMOUNT'] : 'нет записи') . ' → ' . $amount,
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $rowId = (int)($args['row_id'] ?? 0);

        if ($rowId > 0) {
            $result = StoreProductTable::update($rowId, array('AMOUNT' => (float)$args['amount']));
        } else {
            $result = StoreProductTable::add(array(
                'PRODUCT_ID' => (int)$args['product_id'],
                'STORE_ID' => (int)$args['store_id'],
                'AMOUNT' => (float)$args['amount'],
            ));
        }

        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        // Общее количество товара пересчитывает ядро при изменении склада,
        // но при прямой записи в ORM — только через этот вызов.
        if (class_exists('\\CCatalogProduct')) {
            \CCatalogProduct::Update((int)$args['product_id'], array());
        }

        return array(
            'product_id' => (int)$args['product_id'],
            'store_id' => (int)$args['store_id'],
            'amount' => (float)$args['amount'],
            'row_id' => (int)($result->getId() ?: $rowId),
        );
    }

    public static function rollback(array $payload): void
    {
        $args = (array)($payload['args'] ?? array());
        $before = (array)($payload['before'] ?? array());
        $rowId = (int)($args['row_id'] ?? 0);

        if (empty($before['exists'])) {
            $createdId = (int)($payload['result']['row_id'] ?? 0);
            if ($createdId > 0) {
                StoreProductTable::delete($createdId);
            }

            return;
        }

        if ($rowId > 0) {
            StoreProductTable::update($rowId, array('AMOUNT' => (float)$before['amount']));
        }
    }
}
