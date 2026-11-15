<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Sale\Internals\DiscountCouponTable;
use Bitrix\Sale\Internals\DiscountTable;

/** Удаление скидки вместе с её промокодами. */
class DeleteDiscountTool extends AbstractChangeTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'delete_discount';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_discount',
            'description' => 'Удаляет скидку и все её промокоды. Показывает, что именно будет удалено.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'discount_id' => array('type' => 'integer', 'description' => 'ID скидки из list_discounts'),
                ),
                'required' => array('discount_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $discountId = (int)($args['discount_id'] ?? 0);
        if ($discountId <= 0) {
            throw new \InvalidArgumentException('Параметр discount_id обязателен.');
        }

        if (!Loader::includeModule('sale')) {
            throw new \RuntimeException('Модуль sale недоступен.');
        }

        $discount = DiscountTable::getById($discountId)->fetch();
        if (!$discount) {
            throw new \InvalidArgumentException('Скидка #' . $discountId . ' не найдена.');
        }

        $coupons = DiscountCouponTable::getCount(array('=DISCOUNT_ID' => $discountId));

        // Полные строки таблиц: по ним скидка и промокоды восстанавливаются при откате.
        $connection = Application::getConnection();
        $raw = $connection->query(
            'SELECT * FROM b_sale_discount WHERE ID = ' . $discountId
        )->fetch();
        $rawCoupons = $connection->query(
            'SELECT * FROM b_sale_discount_coupon WHERE DISCOUNT_ID = ' . $discountId
        )->fetchAll();

        return array(
            'summary' => 'Удаление скидки «' . $discount['NAME'] . '»',
            'payload' => array(
                'args' => array('discount_id' => $discountId),
                'before' => array(
                    'name' => (string)$discount['NAME'],
                    'coupons' => $coupons,
                    'discount_row' => $raw ?: array(),
                    'coupon_rows' => $rawCoupons,
                ),
            ),
            'preview' => 'Будет удалена скидка «' . $discount['NAME'] . '» (ID ' . $discountId . ')'
                . ($coupons > 0 ? ' и ' . $coupons . ' промокод(ов)' : '') . '.'
                . ' Перед удалением создаётся SQL-дамп.',
        );
    }

    public static function apply(array $payload): array
    {
        $discountId = (int)($payload['args']['discount_id'] ?? 0);

        // deleteByFilter у DiscountCouponTable нет: удаляем промокоды поштучно.
        $coupons = DiscountCouponTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=DISCOUNT_ID' => $discountId),
        ))->fetchAll();

        foreach ($coupons as $coupon) {
            DiscountCouponTable::delete((int)$coupon['ID']);
        }

        $result = DiscountTable::delete($discountId);

        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        return array('discount_id' => $discountId, 'deleted' => true);
    }

    public static function rollback(array $payload): void
    {
        $before = (array)($payload['before'] ?? array());
        $row = isset($before['discount_row']) && is_array($before['discount_row']) ? $before['discount_row'] : array();

        if ($row === array()) {
            throw new \RuntimeException('Нет сохранённой скидки для отката.');
        }

        // Возвращаем строку как была: ID, состояние индекса и вычисленные поля
        // должны совпасть, поэтому пишем напрямую в таблицу, минуя пересчёт.
        $connection = Application::getConnection();
        $connection->add('b_sale_discount', $row);

        foreach ((array)($before['coupon_rows'] ?? array()) as $coupon) {
            if (is_array($coupon) && $coupon !== array()) {
                $connection->add('b_sale_discount_coupon', $coupon);
            }
        }
    }
}
