<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Catalog\PriceTable;
use Bitrix\Main\Loader;

/** Цены товара. */
class GetProductPricesTool extends AbstractTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'get_product_prices';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'get_product_prices',
            'description' => 'Цены товара по всем типам цен: тип, цена, валюта.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'product_id' => array('type' => 'integer', 'description' => 'ID товара (элемента)'),
                ),
                'required' => array('product_id'),
            ),
        );
    }

    /** @return array{product_id:int,prices:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $productId = (int)($args['product_id'] ?? 0);
        if ($productId <= 0) {
            return array('error' => 'Параметр product_id обязателен.');
        }

        if (!Loader::includeModule('catalog')) {
            return array('error' => 'Модуль catalog недоступен.');
        }

        $rows = PriceTable::getList(array(
            'select' => array('ID', 'CATALOG_GROUP_ID', 'PRICE', 'CURRENCY'),
            'filter' => array('=PRODUCT_ID' => $productId),
            'order' => array('CATALOG_GROUP_ID' => 'ASC'),
        ))->fetchAll();

        $prices = array();

        foreach ($rows as $row) {
            $prices[] = array(
                'price_id' => (int)$row['ID'],
                'price_type_id' => (int)$row['CATALOG_GROUP_ID'],
                'price' => (float)$row['PRICE'],
                'currency' => (string)$row['CURRENCY'],
            );
        }

        return array('product_id' => $productId, 'prices' => $prices);
    }
}
