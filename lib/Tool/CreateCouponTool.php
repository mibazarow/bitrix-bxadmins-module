<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Sale\Internals\DiscountCouponTable;
use Bitrix\Sale\Internals\DiscountTable;

/** Создание промокода для существующей скидки. */
class CreateCouponTool extends AbstractChangeTool
{
    public const DOMAIN = 'commerce';

    private const TYPE_ONE_ORDER = 1;

    public static function getName(): string
    {
        return 'create_coupon';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_coupon',
            'description' => 'Создаёт промокод для скидки. Код — латиница и цифры.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'discount_id' => array('type' => 'integer', 'description' => 'ID скидки (см. list_discounts)'),
                    'code' => array('type' => 'string', 'description' => 'Промокод, например SUMMER10'),
                    'max_use' => array('type' => 'integer', 'description' => 'Сколько раз можно применить, 0 — без ограничений'),
                ),
                'required' => array('discount_id', 'code'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $discountId = (int)($args['discount_id'] ?? 0);
        $code = strtoupper(trim((string)($args['code'] ?? '')));
        $maxUse = max(0, (int)($args['max_use'] ?? 0));

        if ($discountId <= 0) {
            throw new \InvalidArgumentException('Параметр discount_id обязателен.');
        }

        if ($code === '' || !preg_match('/^[A-Z0-9_-]{3,32}$/', $code)) {
            throw new \InvalidArgumentException('Код промокода: 3–32 символа, латиница, цифры, дефис и подчёркивание.');
        }

        if (!Loader::includeModule('sale')) {
            throw new \RuntimeException('Модуль sale недоступен.');
        }

        $discount = DiscountTable::getById($discountId)->fetch();
        if (!$discount) {
            throw new \InvalidArgumentException('Скидка #' . $discountId . ' не найдена.');
        }

        $existing = DiscountCouponTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=COUPON' => $code),
            'limit' => 1,
        ))->fetch();

        if ($existing) {
            throw new \InvalidArgumentException('Промокод ' . $code . ' уже существует.');
        }

        return array(
            'summary' => 'Промокод ' . $code . ' для скидки «' . $discount['NAME'] . '»',
            'payload' => array(
                'args' => array(
                    'discount_id' => $discountId,
                    'code' => $code,
                    'max_use' => $maxUse,
                ),
            ),
            'preview' => 'Будет создан промокод ' . $code . ' для скидки «' . $discount['NAME'] . '»'
                . ($maxUse > 0 ? ', применений: ' . $maxUse : ', без ограничения применений') . '.',
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());

        $result = DiscountCouponTable::add(array(
            'DISCOUNT_ID' => (int)$args['discount_id'],
            'COUPON' => (string)$args['code'],
            'TYPE' => self::TYPE_ONE_ORDER,
            'ACTIVE' => 'Y',
            'MAX_USE' => (int)$args['max_use'],
        ));

        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        return array('coupon_id' => (int)$result->getId(), 'code' => (string)$args['code']);
    }

    public static function rollback(array $payload): void
    {
        $couponId = (int)($payload['result']['coupon_id'] ?? 0);
        if ($couponId > 0) {
            DiscountCouponTable::delete($couponId);
        }
    }
}
