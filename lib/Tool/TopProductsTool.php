<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Sale\Internals\BasketTable;
use Bitrix\Sale\Internals\OrderTable;
use Mibazarow\Bxadmins\Service\ReportPeriod;

/**
 * Топ товаров за период по выручке или количеству. Только чтение.
 */
class TopProductsTool extends AbstractTool
{
    public const DOMAIN = 'marketing';

    private const MAX_ORDERS = 20000;
    private const CHUNK = 500;

    public static function getName(): string
    {
        return 'top_products';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'top_products',
            'description' => 'Топ товаров за период: выручка и количество по позициям заказов. Отменённые заказы не учитываются. Только чтение.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'from' => array('type' => 'string', 'description' => 'Начало периода, YYYY-MM-DD'),
                    'to' => array('type' => 'string', 'description' => 'Конец периода, YYYY-MM-DD (по умолчанию сегодня)'),
                    'days' => array('type' => 'integer', 'description' => 'Если from не задан: последние N дней, по умолчанию 30'),
                    'by' => array(
                        'type' => 'string',
                        'enum' => array('revenue', 'quantity'),
                        'description' => 'Сортировка: revenue (по умолчанию) или quantity',
                    ),
                    'limit' => array('type' => 'integer', 'description' => 'Сколько товаров вернуть, по умолчанию 10, максимум 50'),
                ),
            ),
        );
    }

    /** @return array<string,mixed>|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('sale')) {
            return array('error' => 'Модуль sale недоступен — редакция не магазинная.');
        }

        try {
            $period = ReportPeriod::fromArgs($args);
        } catch (\InvalidArgumentException $e) {
            return array('error' => $e->getMessage());
        }

        $by = strtolower(trim((string)($args['by'] ?? 'revenue')));
        if (!in_array($by, array('revenue', 'quantity'), true)) {
            return array('error' => 'by должен быть revenue или quantity.');
        }

        $limit = max(1, min(50, (int)($args['limit'] ?? 10)));

        $orderRows = OrderTable::getList(array(
            'select' => array('ID'),
            'filter' => array(
                '>=DATE_INSERT' => $period['from'],
                '<=DATE_INSERT' => $period['to'],
                '!CANCELED' => 'Y',
            ),
            'limit' => self::MAX_ORDERS,
        ))->fetchAll();

        $orderIds = array_map(static function (array $row): int {
            return (int)$row['ID'];
        }, $orderRows);

        if ($orderIds === array()) {
            return array('period' => $period['label'], 'by' => $by, 'products' => array(), 'orders' => 0);
        }

        $products = array();
        $currencies = array();

        foreach (array_chunk($orderIds, self::CHUNK) as $chunk) {
            $rows = BasketTable::getList(array(
                'select' => array('PRODUCT_ID', 'NAME', 'PRICE', 'QUANTITY', 'SUMMARY_PRICE', 'CURRENCY'),
                'filter' => array('=ORDER_ID' => $chunk),
                'limit' => 50000,
            ))->fetchAll();

            foreach ($rows as $row) {
                $productId = (int)($row['PRODUCT_ID'] ?? 0);
                if ($productId <= 0) {
                    continue;
                }

                $quantity = (float)($row['QUANTITY'] ?? 0);
                $sum = (float)($row['SUMMARY_PRICE'] ?? 0);
                if ($sum <= 0) {
                    $sum = (float)($row['PRICE'] ?? 0) * $quantity;
                }

                if (!isset($products[$productId])) {
                    $products[$productId] = array(
                        'product_id' => $productId,
                        'name' => (string)($row['NAME'] ?? ''),
                        'quantity' => 0.0,
                        'revenue' => 0.0,
                    );
                }

                $products[$productId]['quantity'] += $quantity;
                $products[$productId]['revenue'] += $sum;

                $currency = (string)($row['CURRENCY'] ?? '');
                if ($currency !== '') {
                    $currencies[$currency] = true;
                }
            }
        }

        $list = array_values($products);
        usort($list, static function (array $a, array $b) use ($by): int {
            return $by === 'quantity'
                ? $b['quantity'] <=> $a['quantity']
                : $b['revenue'] <=> $a['revenue'];
        });

        $list = array_slice($list, 0, $limit);
        foreach ($list as &$item) {
            $item['quantity'] = round((float)$item['quantity'], 2);
            $item['revenue'] = round((float)$item['revenue'], 2);
        }
        unset($item);

        return array(
            'period' => $period['label'],
            'by' => $by,
            'orders' => count($orderIds),
            'currency' => implode(', ', array_keys($currencies)),
            'products' => $list,
            'truncated' => count($orderIds) >= self::MAX_ORDERS,
        );
    }
}
