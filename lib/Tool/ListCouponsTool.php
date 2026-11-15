<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Sale\Internals\DiscountCouponTable;

/** Промокоды. */
class ListCouponsTool extends AbstractTool
{
    public const DOMAIN = 'commerce';

    private const LIMIT = 100;

    public static function getName(): string
    {
        return 'list_coupons';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_coupons',
            'description' => 'Промокоды: код, скидка, тип, активность, использований. Максимум 100.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'discount_id' => array('type' => 'integer', 'description' => 'Только коды этой скидки'),
                ),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,coupons:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('sale')) {
            return array('error' => 'Модуль sale недоступен.');
        }

        $filter = array();
        if (($args['discount_id'] ?? 0) > 0) {
            $filter['=DISCOUNT_ID'] = (int)$args['discount_id'];
        }

        $rows = DiscountCouponTable::getList(array(
            'select' => array('ID', 'DISCOUNT_ID', 'COUPON', 'TYPE', 'ACTIVE', 'USE_COUNT', 'MAX_USE'),
            'filter' => $filter,
            'order' => array('ID' => 'DESC'),
            'limit' => self::LIMIT + 1,
        ))->fetchAll();

        $truncated = count($rows) > self::LIMIT;
        $coupons = array();

        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            $coupons[] = array(
                'id' => (int)$row['ID'],
                'discount_id' => (int)$row['DISCOUNT_ID'],
                'coupon' => (string)$row['COUPON'],
                'type' => (int)$row['TYPE'],
                'active' => (string)$row['ACTIVE'] === 'Y',
                'used' => (int)$row['USE_COUNT'],
                'max_use' => (int)$row['MAX_USE'],
            );
        }

        return array('count' => count($coupons), 'truncated' => $truncated, 'coupons' => $coupons);
    }
}
