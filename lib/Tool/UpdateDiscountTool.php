<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Sale\Internals\DiscountTable;

/** Правка скидки: название, активность, размер, условия. */
class UpdateDiscountTool extends AbstractChangeTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'update_discount';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_discount',
            'description' => 'Меняет скидку: название, активность, размер (процент или сумма), условия. Список — list_discounts.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'discount_id' => array('type' => 'integer', 'description' => 'ID скидки'),
                    'name' => array('type' => 'string', 'description' => 'Новое название'),
                    'active' => array('type' => 'boolean', 'description' => 'Включить или выключить'),
                    'value' => array('type' => 'number', 'description' => 'Размер скидки'),
                    'min_order_sum' => array('type' => 'number', 'description' => 'Минимальная сумма заказа'),
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

        $discount = DiscountTable::getList(array(
            // CONDITIONS_LIST и ACTIONS_LIST — виртуальные поля ORM: в базе они
            // лежат в CONDITIONS/ACTIONS как serialized, D7 отдаёт массивами.
            'select' => array('ID', 'NAME', 'ACTIVE', 'SORT', 'LID', 'CONDITIONS_LIST', 'ACTIONS_LIST'),
            'filter' => array('=ID' => $discountId),
            'limit' => 1,
        ))->fetch();

        if (!$discount) {
            throw new \InvalidArgumentException('Скидка #' . $discountId . ' не найдена.');
        }

        $fields = array();
        $lines = array();

        if (array_key_exists('name', $args)) {
            $name = trim((string)$args['name']);
            if ($name !== '' && $name !== (string)$discount['NAME']) {
                $fields['NAME'] = $name;
                $lines[] = 'Название: ' . $discount['NAME'] . ' → ' . $name;
            }
        }

        if (array_key_exists('active', $args)) {
            $active = ((bool)$args['active']) ? 'Y' : 'N';
            if ($active !== (string)$discount['ACTIVE']) {
                $fields['ACTIVE'] = $active;
                $lines[] = 'Активность: ' . $discount['ACTIVE'] . ' → ' . $active;
            }
        }

        $value = $args['value'] ?? null;
        $minSum = $args['min_order_sum'] ?? null;

        if ($value !== null || $minSum !== null) {
            $actions = is_array($discount['ACTIONS_LIST'] ?? null) ? $discount['ACTIONS_LIST'] : array();
            $conditions = is_array($discount['CONDITIONS_LIST'] ?? null) ? $discount['CONDITIONS_LIST'] : array();

            if ($value !== null) {
                $actions = self::withActionValue($actions, (float)$value);
                $lines[] = 'Размер: → ' . (float)$value;
            }

            if ($minSum !== null) {
                $conditions = self::withMinOrderSum($conditions, (float)$minSum);
                $lines[] = 'Минимальная сумма: → ' . (float)$minSum;
            }

            $fields['ACTIONS_LIST'] = $actions;
            $fields['CONDITIONS_LIST'] = $conditions;
        }

        if ($fields === array()) {
            throw new \InvalidArgumentException('Изменений нет: переданные значения совпадают с текущими.');
        }

        return array(
            'summary' => 'Скидка #' . $discountId . ': ' . implode(', ', array_keys($fields)),
            'payload' => array(
                'args' => array('discount_id' => $discountId, 'fields' => $fields),
                'before' => array(
                    'NAME' => (string)$discount['NAME'],
                    'ACTIVE' => (string)$discount['ACTIVE'],
                    'raw' => Application::getConnection()->query(
                        'SELECT * FROM b_sale_discount WHERE ID = ' . $discountId
                    )->fetch() ?: array(),
                ),
            ),
            'preview' => 'Скидка «' . $discount['NAME'] . "»\n" . implode("\n", $lines),
        );
    }

    /**
     * Размер скидки живёт не в корне ACTIONS_LIST, а в данных первого действия
     * (ActSaleBsktGrp): Value/Unit/Currency. Корневые VALUE/TYPE ядро игнорирует.
     */
    private static function withActionValue(array $actions, float $value): array
    {
        $children = is_array($actions['CHILDREN'] ?? null) ? $actions['CHILDREN'] : array();

        if ($children === array()) {
            $actions['CLASS_ID'] = $actions['CLASS_ID'] ?? 'CondGroup';
            $actions['DATA'] = is_array($actions['DATA'] ?? null) ? $actions['DATA'] : array('All' => 'AND');
            $children[] = array(
                'CLASS_ID' => 'ActSaleBsktGrp',
                'DATA' => array(),
                'CHILDREN' => array(),
            );
        }

        $children[0]['DATA'] = is_array($children[0]['DATA'] ?? null) ? $children[0]['DATA'] : array();
        $children[0]['DATA']['Type'] = $children[0]['DATA']['Type'] ?? 'Discount';
        $children[0]['DATA']['Value'] = $value;
        $children[0]['DATA']['Unit'] = $children[0]['DATA']['Unit'] ?? 'Perc';
        $children[0]['DATA']['Currency'] = $children[0]['DATA']['Currency'] ?? 'RUB';

        $actions['CHILDREN'] = $children;

        return $actions;
    }

    /** Минимальная сумма заказа: условие CondBsktAmt внутри CondBsktAmtGroup. */
    private static function withMinOrderSum(array $conditions, float $minSum): array
    {
        $children = is_array($conditions['CHILDREN'] ?? null) ? $conditions['CHILDREN'] : array();
        $condition = array(
            'CLASS_ID' => 'CondBsktAmt',
            'DATA' => array('logic' => '>=', 'value' => $minSum),
        );

        $found = false;
        foreach ($children as $index => $child) {
            if (is_array($child) && ($child['CLASS_ID'] ?? '') === 'CondBsktAmtGroup') {
                $children[$index]['DATA'] = is_array($child['DATA'] ?? null)
                    ? $child['DATA']
                    : array('All' => 'AND', 'logic' => 'True');
                $children[$index]['CHILDREN'] = array($condition);
                $found = true;
            }
        }

        if (!$found) {
            $children[] = array(
                'CLASS_ID' => 'CondBsktAmtGroup',
                'DATA' => array('All' => 'AND', 'logic' => 'True'),
                'CHILDREN' => array($condition),
            );
        }

        $conditions['CLASS_ID'] = $conditions['CLASS_ID'] ?? 'CondGroup';
        $conditions['DATA'] = is_array($conditions['DATA'] ?? null) ? $conditions['DATA'] : array('All' => 'AND');
        $conditions['CHILDREN'] = $children;

        return $conditions;
    }

    public static function apply(array $payload): array
    {
        $discountId = (int)($payload['args']['discount_id'] ?? 0);
        $fields = (array)($payload['args']['fields'] ?? array());

        if ($discountId <= 0 || $fields === array()) {
            throw new \InvalidArgumentException('Нет данных для изменения скидки.');
        }

        $result = DiscountTable::update($discountId, $fields);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        return array('discount_id' => $discountId, 'updated' => array_keys($fields));
    }

    public static function rollback(array $payload): void
    {
        $discountId = (int)($payload['args']['discount_id'] ?? 0);
        $raw = (array)($payload['before']['raw'] ?? array());

        if ($discountId <= 0 || $raw === array()) {
            return;
        }

        // Полная строка из снимка: возвращаем и вычисленные поля, чтобы индексы совпали.
        $connection = Application::getConnection();
        $connection->queryExecute(
            'DELETE FROM b_sale_discount WHERE ID = ' . $discountId
        );
        $connection->add('b_sale_discount', $raw);
    }

}
