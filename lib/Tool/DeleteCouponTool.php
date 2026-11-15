<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Sale\Internals\DiscountCouponTable;

/** Удаление промокода. */
class DeleteCouponTool extends AbstractChangeTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'delete_coupon';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_coupon',
            'description' => 'Удаляет промокод по коду или ID.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'coupon' => array('type' => 'string', 'description' => 'Код промокода'),
                    'coupon_id' => array('type' => 'integer', 'description' => 'ID промокода, если код неизвестен'),
                ),
            ),
        );
    }

    public static function preview(array $args): array
    {
        if (!Loader::includeModule('sale')) {
            throw new \RuntimeException('Модуль sale недоступен.');
        }

        $couponId = (int)($args['coupon_id'] ?? 0);
        $code = strtoupper(trim((string)($args['coupon'] ?? '')));

        $filter = $couponId > 0 ? array('=ID' => $couponId) : array('=COUPON' => $code);

        if ($couponId <= 0 && $code === '') {
            throw new \InvalidArgumentException('Нужен coupon или coupon_id.');
        }

        $row = DiscountCouponTable::getList(array(
            'select' => array('ID', 'COUPON', 'DISCOUNT_ID'),
            'filter' => $filter,
            'limit' => 1,
        ))->fetch();

        if (!$row) {
            throw new \InvalidArgumentException('Промокод не найден.');
        }

        $raw = Application::getConnection()->query(
            'SELECT * FROM b_sale_discount_coupon WHERE ID = ' . (int)$row['ID']
        )->fetch();

        return array(
            'summary' => 'Удаление промокода ' . $row['COUPON'],
            'payload' => array(
                'args' => array('coupon_id' => (int)$row['ID']),
                'before' => array(
                    'coupon' => (string)$row['COUPON'],
                    'discount_id' => (int)$row['DISCOUNT_ID'],
                    'coupon_row' => $raw ?: array(),
                ),
            ),
            'preview' => 'Будет удалён промокод ' . $row['COUPON'] . ' (скидка #' . $row['DISCOUNT_ID'] . ').',
        );
    }

    public static function apply(array $payload): array
    {
        $couponId = (int)($payload['args']['coupon_id'] ?? 0);

        $result = DiscountCouponTable::delete($couponId);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        return array('coupon_id' => $couponId, 'deleted' => true);
    }

    public static function rollback(array $payload): void
    {
        $before = (array)($payload['before'] ?? array());
        $row = isset($before['coupon_row']) && is_array($before['coupon_row']) ? $before['coupon_row'] : array();

        if ($row === array()) {
            throw new \RuntimeException('Нет сохранённого промокода для отката.');
        }

        Application::getConnection()->add('b_sale_discount_coupon', $row);
    }
}
