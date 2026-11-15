<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Catalog\StoreProductTable;
use Bitrix\Main\Loader;

/** Остатки товара по складам. */
class GetStoreStockTool extends AbstractTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'get_store_stock';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'get_store_stock',
            'description' => 'Остатки товара по складам: склад, количество, резерв.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'product_id' => array('type' => 'integer', 'description' => 'ID товара'),
                ),
                'required' => array('product_id'),
            ),
        );
    }

    /** @return array{product_id:int,total:float,stocks:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $productId = (int)($args['product_id'] ?? 0);
        if ($productId <= 0) {
            return array('error' => 'Параметр product_id обязателен.');
        }

        if (!Loader::includeModule('catalog')) {
            return array('error' => 'Модуль catalog недоступен.');
        }

        $stocks = array();
        $total = 0.0;

        foreach (StoreProductTable::getList(array(
            'select' => array('STORE_ID', 'AMOUNT', 'QUANTITY_RESERVED'),
            'filter' => array('=PRODUCT_ID' => $productId),
        ))->fetchAll() as $row) {
            $amount = (float)$row['AMOUNT'];
            $total += $amount;
            $stocks[] = array(
                'store_id' => (int)$row['STORE_ID'],
                'amount' => $amount,
                'reserved' => (float)$row['QUANTITY_RESERVED'],
            );
        }

        return array('product_id' => $productId, 'total' => $total, 'stocks' => $stocks);
    }
}
