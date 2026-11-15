<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Iblock\ElementPropertyTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Loader;

/** Торговые предложения (SKU) товара. */
class ListOffersTool extends AbstractTool
{
    public const DOMAIN = 'commerce';

    private const LIMIT = 100;

    public static function getName(): string
    {
        return 'list_offers';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_offers',
            'description' => 'Торговые предложения (SKU) товара: ID, название, активность, цены, остаток.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'product_id' => array('type' => 'integer', 'description' => 'ID товара-родителя'),
                ),
                'required' => array('product_id'),
            ),
        );
    }

    /** @return array{product_id:int,offers_iblock_id:int,count:int,offers:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $productId = (int)($args['product_id'] ?? 0);
        if ($productId <= 0) {
            return array('error' => 'Параметр product_id обязателен.');
        }

        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            return array('error' => 'Модули iblock/catalog недоступны.');
        }

        $product = ElementTable::getList(array(
            'select' => array('ID', 'IBLOCK_ID'),
            'filter' => array('=ID' => $productId),
            'limit' => 1,
        ))->fetch();

        if (!$product) {
            return array('error' => 'Товар #' . $productId . ' не найден.');
        }

        // Связка SKU: свойство в инфоблоке предложений, ссылающееся на инфоблок товара.
        $skuProperty = PropertyTable::getList(array(
            'select' => array('ID', 'IBLOCK_ID'),
            'filter' => array(
                '=USER_TYPE' => 'SKU',
                '=LINK_IBLOCK_ID' => (int)$product['IBLOCK_ID'],
            ),
            'limit' => 1,
        ))->fetch();

        if (!$skuProperty) {
            return array(
                'product_id' => $productId,
                'offers_iblock_id' => 0,
                'count' => 0,
                'offers' => array(),
                'note' => 'У инфоблока товара нет торговых предложений.',
            );
        }

        $offerIds = array();
        foreach (ElementPropertyTable::getList(array(
            'select' => array('IBLOCK_ELEMENT_ID'),
            'filter' => array(
                '=IBLOCK_PROPERTY_ID' => (int)$skuProperty['ID'],
                '=VALUE' => (string)$productId,
            ),
            'limit' => self::LIMIT,
        ))->fetchAll() as $row) {
            $offerIds[] = (int)$row['IBLOCK_ELEMENT_ID'];
        }

        if ($offerIds === array()) {
            return array(
                'product_id' => $productId,
                'offers_iblock_id' => (int)$skuProperty['IBLOCK_ID'],
                'count' => 0,
                'offers' => array(),
            );
        }

        $prices = self::prices($offerIds);
        $stock = self::stock($offerIds);
        $offers = array();

        foreach (ElementTable::getList(array(
            'select' => array('ID', 'NAME', 'CODE', 'ACTIVE', 'SORT'),
            'filter' => array('=ID' => $offerIds),
            'order' => array('SORT' => 'ASC', 'ID' => 'ASC'),
        ))->fetchAll() as $row) {
            $id = (int)$row['ID'];
            $offers[] = array(
                'id' => $id,
                'name' => (string)$row['NAME'],
                'code' => (string)($row['CODE'] ?? ''),
                'active' => (string)$row['ACTIVE'] === 'Y',
                'prices' => $prices[$id] ?? array(),
                'stock' => $stock[$id] ?? 0.0,
            );
        }

        return array(
            'product_id' => $productId,
            'offers_iblock_id' => (int)$skuProperty['IBLOCK_ID'],
            'count' => count($offers),
            'offers' => $offers,
        );
    }

    /** @param int[] $offerIds @return array<int,array> */
    private static function prices(array $offerIds): array
    {
        $result = array();

        foreach (PriceTable::getList(array(
            'select' => array('PRODUCT_ID', 'CATALOG_GROUP_ID', 'PRICE', 'CURRENCY'),
            'filter' => array('=PRODUCT_ID' => $offerIds),
        ))->fetchAll() as $row) {
            $result[(int)$row['PRODUCT_ID']][] = array(
                'price_type_id' => (int)$row['CATALOG_GROUP_ID'],
                'price' => (float)$row['PRICE'],
                'currency' => (string)$row['CURRENCY'],
            );
        }

        return $result;
    }

    /** @param int[] $offerIds @return array<int,float> */
    private static function stock(array $offerIds): array
    {
        $result = array();

        foreach (StoreProductTable::getList(array(
            'select' => array('PRODUCT_ID', 'AMOUNT'),
            'filter' => array('=PRODUCT_ID' => $offerIds),
        ))->fetchAll() as $row) {
            $id = (int)$row['PRODUCT_ID'];
            $result[$id] = ($result[$id] ?? 0.0) + (float)$row['AMOUNT'];
        }

        return $result;
    }
}
