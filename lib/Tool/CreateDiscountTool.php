<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Sale\Internals\DiscountTable;

/**
 * Создание простой скидки на сумму заказа (процент или фиксированная сумма).
 * Сложные условия (товары, группы, периоды) настраиваются в админке.
 */
class CreateDiscountTool extends AbstractChangeTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'create_discount';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_discount',
            'description' => 'Создаёт скидку на сумму заказа: процент или фиксированная сумма. Действует на все товары.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'name' => array('type' => 'string', 'description' => 'Название скидки'),
                    'value' => array('type' => 'number', 'description' => 'Размер скидки'),
                    'unit' => array('type' => 'string', 'description' => 'percent (по умолчанию) или currency'),
                    'currency' => array('type' => 'string', 'description' => 'Валюта для фиксированной суммы, по умолчанию RUB'),
                    'min_order_sum' => array('type' => 'number', 'description' => 'Минимальная сумма заказа, необязательно'),
                    'site_id' => array('type' => 'string', 'description' => 'ID сайта, по умолчанию текущий'),
                ),
                'required' => array('name', 'value'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $name = IblockElementFields::name($args['name'] ?? null);
        $value = (float)($args['value'] ?? 0);
        $unit = strtolower(trim((string)($args['unit'] ?? 'percent')));
        $currency = strtoupper(trim((string)($args['currency'] ?? 'RUB'))) ?: 'RUB';
        $siteId = trim((string)($args['site_id'] ?? '')) ?: self::defaultSiteId();
        $minSum = (float)($args['min_order_sum'] ?? 0);

        if ($value <= 0) {
            throw new \InvalidArgumentException('Размер скидки должен быть больше нуля.');
        }

        if (!in_array($unit, array('percent', 'currency'), true)) {
            throw new \InvalidArgumentException('unit должен быть percent или currency.');
        }

        if ($unit === 'percent' && $value > 100) {
            throw new \InvalidArgumentException('Процент скидки не может быть больше 100.');
        }

        $conditions = array(
            'CLASS_ID' => 'CondGroup',
            'DATA' => array('All' => 'AND'),
            'CHILDREN' => array(),
        );

        if ($minSum > 0) {
            $conditions['CHILDREN'][] = array(
                'CLASS_ID' => 'CondBsktAmtGroup',
                'DATA' => array('All' => 'AND', 'logic' => 'True'),
                'CHILDREN' => array(
                    array(
                        'CLASS_ID' => 'CondBsktAmt',
                        'DATA' => array('logic' => '>=', 'value' => $minSum),
                    ),
                ),
            );
        }

        $actions = array(
            'CLASS_ID' => 'CondGroup',
            'DATA' => array('All' => 'AND'),
            'CHILDREN' => array(
                array(
                    'CLASS_ID' => 'ActSaleBsktGrp',
                    'DATA' => array(
                        'Type' => $unit === 'percent' ? 'Discount' : 'Discount',
                        'Value' => $value,
                        'Unit' => $unit === 'percent' ? 'Perc' : 'Cur',
                        'Currency' => $currency,
                        'All' => 'AND',
                    ),
                    'CHILDREN' => array(),
                ),
            ),
        );

        $text = $unit === 'percent'
            ? $value . '%'
            : number_format($value, 2, '.', ' ') . ' ' . $currency;

        return array(
            'summary' => 'Скидка «' . $name . '» — ' . $text,
            'payload' => array(
                'args' => array(
                    'name' => $name,
                    'value' => $value,
                    'unit' => $unit,
                    'currency' => $currency,
                    'conditions' => $conditions,
                    'actions' => $actions,
                    'site_id' => $siteId,
                ),
            ),
            'preview' => 'Будет создана скидка «' . $name . '»: ' . $text
                . ($minSum > 0 ? ' при сумме заказа от ' . number_format($minSum, 2, '.', ' ') . ' ' . $currency : '')
                . '. Действует на все товары.',
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());

        if (!Loader::includeModule('sale')) {
            throw new \RuntimeException('Модуль sale недоступен.');
        }

        $result = DiscountTable::add(array(
            'NAME' => (string)$args['name'],
            // Скидка привязана к сайту: без LID ядро не создаёт запись.
            'LID' => (string)($args['site_id'] ?? '') !== '' ? (string)$args['site_id'] : self::defaultSiteId(),
            'ACTIVE' => 'Y',
            'SORT' => 100,
            'PRIORITY' => 1,
            'LAST_DISCOUNT' => 'N',
            // CONDITIONS/ACTIONS в этой версии — вычисляемые поля; запись идёт в *_LIST.
            'CONDITIONS_LIST' => $args['conditions'],
            'ACTIONS_LIST' => $args['actions'],
        ));

        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        return array('discount_id' => (int)$result->getId(), 'name' => (string)$args['name']);
    }

    public static function rollback(array $payload): void
    {
        $discountId = (int)($payload['result']['discount_id'] ?? 0);
        if ($discountId > 0) {
            DiscountTable::delete($discountId);
        }
    }

    /** Сайт по умолчанию: в демоне SITE_ID может быть не определён. */
    private static function defaultSiteId(): string
    {
        if (defined('SITE_ID') && (string)SITE_ID !== '') {
            return (string)SITE_ID;
        }

        $row = \Bitrix\Main\SiteTable::getList(array(
            'select' => array('LID'),
            'filter' => array('=ACTIVE' => 'Y', '=DEF' => 'Y'),
            'limit' => 1,
        ))->fetch();

        return $row ? (string)$row['LID'] : 's1';
    }
}
