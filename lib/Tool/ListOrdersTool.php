<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Sale\Internals\OrderTable;

/** Заказы магазина. */
class ListOrdersTool extends AbstractTool
{
    public const DOMAIN = 'commerce';

    private const LIMIT = 200;

    public static function getName(): string
    {
        return 'list_orders';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_orders',
            'description' => 'Заказы: номер, дата, статус, сумма, оплата, покупатель, доставка. Фильтры по периоду, статусу, покупателю, оплате, отмене и поиску по клиенту. Максимум 200.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'offset' => array('type' => 'integer', 'description' => 'Пропустить N записей (для следующей страницы)'),
                    'limit' => array('type' => 'integer', 'description' => 'Сколько вернуть, по умолчанию 50, максимум 200'),
                    'status_id' => array('type' => 'string', 'description' => 'Фильтр по статусу'),
                    'user_id' => array('type' => 'integer', 'description' => 'Фильтр по покупателю'),
                    'date_from' => array('type' => 'string', 'description' => 'С даты, YYYY-MM-DD'),
                    'date_to' => array('type' => 'string', 'description' => 'По дату, YYYY-MM-DD'),
                    'account_number' => array('type' => 'string', 'description' => 'Номер заказа'),
                    'search' => array('type' => 'string', 'description' => 'Поиск по имени, фамилии, e-mail или логину покупателя'),
                    'payed' => array('type' => 'boolean', 'description' => 'Только оплаченные (true) или неоплаченные (false)'),
                    'canceled' => array('type' => 'boolean', 'description' => 'Только отменённые (true) или неотменённые (false)'),
                ),
            ),
        );
    }

    /** @return array{count:int,orders:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('sale')) {
            return array('error' => 'Модуль sale недоступен — редакция не магазинная.');
        }

        $filter = array();
        if (($args['status_id'] ?? '') !== '') {
            $filter['=STATUS_ID'] = (string)$args['status_id'];
        }
        if (($args['user_id'] ?? 0) > 0) {
            $filter['=USER_ID'] = (int)$args['user_id'];
        }
        if (($args['account_number'] ?? '') !== '') {
            $filter['=ACCOUNT_NUMBER'] = (string)$args['account_number'];
        }
        if (array_key_exists('payed', $args)) {
            $filter['=PAYED'] = !empty($args['payed']) ? 'Y' : 'N';
        }
        if (array_key_exists('canceled', $args)) {
            $filter['=CANCELED'] = !empty($args['canceled']) ? 'Y' : 'N';
        }

        $from = trim((string)($args['date_from'] ?? ''));
        $to = trim((string)($args['date_to'] ?? ''));
        if ($from !== '') {
            $filter['>=DATE_INSERT'] = \Bitrix\Main\Type\DateTime::createFromPhp(new \DateTime($from . ' 00:00:00'));
        }
        if ($to !== '') {
            $filter['<=DATE_INSERT'] = \Bitrix\Main\Type\DateTime::createFromPhp(new \DateTime($to . ' 23:59:59'));
        }

        $search = trim((string)($args['search'] ?? ''));
        if ($search !== '') {
            $users = \Bitrix\Main\UserTable::getList(array(
                'select' => array('ID'),
                'filter' => array(
                    'LOGIC' => 'OR',
                    '%NAME' => $search,
                    '%LAST_NAME' => $search,
                    '%EMAIL' => $search,
                    '%LOGIN' => $search,
                ),
                'limit' => 200,
            ))->fetchAll();

            $userIds = array_map(static function (array $row): int {
                return (int)$row['ID'];
            }, $users);

            if ($userIds === array()) {
                return array('count' => 0, 'orders' => array());
            }

            $filter['=USER_ID'] = $userIds;
        }

        $limit = max(1, min(self::LIMIT, (int)($args['limit'] ?? self::LIMIT)));

        $rows = OrderTable::getList(array(
            'select' => array(
                'ID', 'ACCOUNT_NUMBER', 'DATE_INSERT', 'STATUS_ID', 'PRICE', 'CURRENCY',
                'USER_ID', 'PAYED', 'CANCELED', 'TRACKING_NUMBER', 'DELIVERY_ID', 'PAY_SYSTEM_ID',
            ),
            'filter' => $filter,
            'order' => array('ID' => 'DESC'),
            'offset' => max(0, (int)($args['offset'] ?? 0)),
            'limit' => $limit,
        ))->fetchAll();

        $orders = array();

        foreach ($rows as $row) {
            $orders[] = array(
                'id' => (int)$row['ID'],
                'number' => (string)($row['ACCOUNT_NUMBER'] ?? $row['ID']),
                'date' => $row['DATE_INSERT'] instanceof \Bitrix\Main\Type\Date
                    ? $row['DATE_INSERT']->toString()
                    : (string)$row['DATE_INSERT'],
                'status' => (string)$row['STATUS_ID'],
                'price' => (float)$row['PRICE'],
                'currency' => (string)$row['CURRENCY'],
                'user_id' => (int)$row['USER_ID'],
                'payed' => (string)$row['PAYED'] === 'Y',
                'canceled' => (string)$row['CANCELED'] === 'Y',
                'tracking_number' => (string)($row['TRACKING_NUMBER'] ?? ''),
                'delivery_id' => (int)($row['DELIVERY_ID'] ?? 0),
                'pay_system_id' => (int)($row['PAY_SYSTEM_ID'] ?? 0),
            );
        }

        return array('count' => count($orders), 'orders' => $orders);
    }
}
