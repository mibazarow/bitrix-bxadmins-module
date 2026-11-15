<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Sale\Internals\DiscountTable;

/** Скидки магазина. */
class ListDiscountsTool extends AbstractTool
{
    public const DOMAIN = 'commerce';

    private const LIMIT = 100;

    public static function getName(): string
    {
        return 'list_discounts';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_discounts',
            'description' => 'Скидки и правила корзины: ID, название, активность, приоритет, условия. Максимум 100.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'active_only' => array('type' => 'boolean', 'description' => 'Только активные'),
                ),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,discounts:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('sale')) {
            return array('error' => 'Модуль sale недоступен.');
        }

        $filter = array();
        if (($args['active_only'] ?? false) === true) {
            $filter['=ACTIVE'] = 'Y';
        }

        $rows = DiscountTable::getList(array(
            'select' => array('ID', 'NAME', 'ACTIVE', 'PRIORITY', 'SORT', 'DISCOUNT_VALUE', 'CURRENCY', 'LAST_DISCOUNT', 'ACTIONS_LIST'),
            'filter' => $filter,
            'order' => array('PRIORITY' => 'ASC', 'SORT' => 'ASC'),
            'limit' => self::LIMIT + 1,
        ))->fetchAll();

        $truncated = count($rows) > self::LIMIT;
        $discounts = array();

        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            $value = (float)$row['DISCOUNT_VALUE'];
            $currency = (string)($row['CURRENCY'] ?? '');
            $unit = '';

            // Новые скидки хранят размер в первом действии, а DISCOUNT_VALUE остаётся нулём.
            if ($value <= 0 && is_array($row['ACTIONS_LIST'] ?? null)) {
                $data = $row['ACTIONS_LIST']['CHILDREN'][0]['DATA'] ?? null;

                if (is_array($data)) {
                    $value = (float)($data['Value'] ?? 0);
                    $currency = $currency !== '' ? $currency : (string)($data['Currency'] ?? '');
                    $unit = (string)($data['Unit'] ?? '') === 'Perc' ? 'percent' : 'currency';
                }
            }

            $discounts[] = array(
                'id' => (int)$row['ID'],
                'name' => (string)$row['NAME'],
                'active' => (string)$row['ACTIVE'] === 'Y',
                'priority' => (int)$row['PRIORITY'],
                'value' => $value,
                'unit' => $unit,
                'currency' => $currency,
                'last_discount' => (string)$row['LAST_DISCOUNT'] === 'Y',
            );
        }

        return array('count' => count($discounts), 'truncated' => $truncated, 'discounts' => $discounts);
    }
}
