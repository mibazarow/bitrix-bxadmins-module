<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Loader;

/**
 * Торговые предложения товара.
 *
 * Цена базы и цена предложения — разные записи. Перед правкой цены нужно понять,
 * что перед нами: обычный товар или товар с предложениями.
 */
final class CatalogOffers
{
    /** @return int[] ID торговых предложений; пусто — предложений нет. */
    public static function offersFor(int $productId): array
    {
        if ($productId <= 0 || !Loader::includeModule('catalog') || !Loader::includeModule('iblock')) {
            return array();
        }

        try {
            // CCatalogSKU — единственный доступный способ узнать связь «товар → предложения».
            $info = \CCatalogSKU::GetProductInfo($productId);
        } catch (\Throwable $e) {
            return array();
        }

        $offers = is_array($info) && !empty($info['OFFERS']) ? (array)$info['OFFERS'] : array();

        return array_values(array_filter(array_map('intval', $offers), static fn (int $id): bool => $id > 0));
    }

    /**
     * Какие из товаров имеют торговые предложения — одним запросом.
     *
     * @param int[] $productIds
     * @return array<int,true>
     */
    public static function baseProductsWithOffers(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($productIds === array()) {
            return array();
        }

        try {
            $rows = \Bitrix\Main\Application::getConnection()->query(
                "SELECT DISTINCT ep.VALUE FROM b_iblock_element_property ep "
                . "INNER JOIN b_iblock_property p ON p.ID = ep.IBLOCK_PROPERTY_ID "
                . "WHERE p.CODE = 'CML2_LINK'"
            )->fetchAll();
        } catch (\Throwable $e) {
            return array();
        }

        $linked = array();
        foreach ($rows as $row) {
            $value = (int)($row['VALUE'] ?? 0);
            if ($value > 0) {
                $linked[$value] = true;
            }
        }

        $result = array();
        foreach ($productIds as $id) {
            if (isset($linked[$id])) {
                $result[$id] = true;
            }
        }

        return $result;
    }

    /** @return array<int,string> ID => название */
    public static function names(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === array() || !Loader::includeModule('iblock')) {
            return array();
        }

        $names = array();
        $list = \CIBlockElement::GetList(
            array('ID' => 'ASC'),
            array('ID' => $ids),
            false,
            false,
            array('ID', 'NAME')
        );

        while ($row = $list->Fetch()) {
            $names[(int)$row['ID']] = (string)$row['NAME'];
        }

        return $names;
    }

    public static function hint(array $offers): string
    {
        $names = self::names($offers);
        $parts = array();

        foreach (array_slice($offers, 0, 10) as $id) {
            $parts[] = '#' . $id . (isset($names[$id]) ? ' «' . $names[$id] . '»' : '');
        }

        return implode(', ', $parts) . (count($offers) > 10 ? ' и ещё ' . (count($offers) - 10) : '');
    }
}
