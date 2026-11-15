<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Sale\Internals\OrderTable;
use Mibazarow\Bxadmins\Service\ReportPeriod;

/**
 * Отчёт по продажам за период: заказы, выручка, оплачено, средний чек,
 * разбивка по дням/месяцам и статусам. Только чтение.
 */
class SalesReportTool extends AbstractTool
{
    public const DOMAIN = 'marketing';

    private const MAX_ORDERS = 20000;

    public static function getName(): string
    {
        return 'sales_report';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'sales_report',
            'description' => 'Отчёт по продажам за период: количество заказов, выручка, оплаченная сумма, средний чек, разбивка по дням/месяцам и статусам. Отменённые заказы в выручку не входят. Только чтение.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'from' => array('type' => 'string', 'description' => 'Начало периода, YYYY-MM-DD'),
                    'to' => array('type' => 'string', 'description' => 'Конец периода, YYYY-MM-DD (по умолчанию сегодня)'),
                    'days' => array('type' => 'integer', 'description' => 'Если from не задан: последние N дней, по умолчанию 30'),
                    'group_by' => array(
                        'type' => 'string',
                        'enum' => array('day', 'month', 'status'),
                        'description' => 'Разбивка: day (по умолчанию), month или status',
                    ),
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

        $groupBy = strtolower(trim((string)($args['group_by'] ?? 'day')));
        if (!in_array($groupBy, array('day', 'month', 'status'), true)) {
            return array('error' => 'group_by должен быть day, month или status.');
        }

        $rows = OrderTable::getList(array(
            'select' => array(
                'ID', 'DATE_INSERT', 'STATUS_ID', 'PRICE', 'CURRENCY',
                'PAYED', 'CANCELED', 'DISCOUNT_VALUE', 'SUM_PAID',
            ),
            'filter' => array(
                '>=DATE_INSERT' => $period['from'],
                '<=DATE_INSERT' => $period['to'],
            ),
            'order' => array('DATE_INSERT' => 'ASC'),
            'limit' => self::MAX_ORDERS,
        ))->fetchAll();

        $orders = 0;
        $canceled = 0;
        $paidOrders = 0;
        $revenue = 0.0;
        $paidRevenue = 0.0;
        $discount = 0.0;
        $currencies = array();
        $series = array();
        $statuses = array();

        foreach ($rows as $row) {
            $orders++;
            $isCanceled = (string)($row['CANCELED'] ?? 'N') === 'Y';
            $sum = (float)($row['PRICE'] ?? 0);
            $currency = (string)($row['CURRENCY'] ?? '');
            $status = (string)($row['STATUS_ID'] ?? '');

            if ($currency !== '') {
                $currencies[$currency] = true;
            }

            $statuses[$status] = ($statuses[$status] ?? 0) + 1;

            if ($isCanceled) {
                $canceled++;
            } else {
                $revenue += $sum;
                $discount += (float)($row['DISCOUNT_VALUE'] ?? 0);

                if ((string)($row['PAYED'] ?? 'N') === 'Y') {
                    $paidOrders++;
                    $paidRevenue += (float)($row['SUM_PAID'] ?? $sum);
                }
            }

            $date = $row['DATE_INSERT'] ?? null;
            $timestamp = $date instanceof \Bitrix\Main\Type\DateTime
                ? $date->getTimestamp()
                : (int)strtotime((string)$date);
            $key = $groupBy === 'month' ? date('Y-m', $timestamp) : date('Y-m-d', $timestamp);

            if ($groupBy !== 'status') {
                if (!isset($series[$key])) {
                    $series[$key] = array('period' => $key, 'orders' => 0, 'revenue' => 0.0);
                }

                $series[$key]['orders']++;
                if (!$isCanceled) {
                    $series[$key]['revenue'] += $sum;
                }
            }
        }

        $payable = $orders - $canceled;
        $series = array_values($series);
        usort($series, static function (array $a, array $b): int {
            return $a['period'] <=> $b['period'];
        });
        foreach ($series as &$item) {
            $item['revenue'] = round((float)$item['revenue'], 2);
        }
        unset($item);

        $statusList = array();
        foreach ($statuses as $code => $count) {
            $statusList[] = array('status_id' => $code, 'orders' => $count);
        }
        usort($statusList, static function (array $a, array $b): int {
            return $b['orders'] <=> $a['orders'];
        });

        return array(
            'period' => $period['label'],
            'group_by' => $groupBy,
            'orders' => $orders,
            'canceled' => $canceled,
            'paid_orders' => $paidOrders,
            'revenue' => round($revenue, 2),
            'paid_revenue' => round($paidRevenue, 2),
            'discount' => round($discount, 2),
            'average_order' => $payable > 0 ? round($revenue / $payable, 2) : 0.0,
            'currency' => implode(', ', array_keys($currencies)),
            'series' => $series,
            'statuses' => $statusList,
            'truncated' => $orders >= self::MAX_ORDERS,
        );
    }
}
