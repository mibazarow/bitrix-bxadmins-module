<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Sale\Internals\OrderTable;
use Bitrix\Sale\Order;

/** Смена статуса заказа. */
class UpdateOrderStatusTool extends AbstractChangeTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'update_order_status';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_order_status',
            'description' => 'Меняет статус заказа. Деньги не трогает — только статус.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'order_id' => array('type' => 'integer', 'description' => 'ID заказа'),
                    'status_id' => array('type' => 'string', 'description' => 'Код статуса, например N или F'),
                ),
                'required' => array('order_id', 'status_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $orderId = (int)($args['order_id'] ?? 0);
        $statusId = trim((string)($args['status_id'] ?? ''));

        if ($orderId <= 0 || $statusId === '') {
            throw new \InvalidArgumentException('Нужны order_id и status_id.');
        }

        if (!Loader::includeModule('sale')) {
            throw new \RuntimeException('Модуль sale недоступен.');
        }

        $order = OrderTable::getList(array(
            'select' => array('ID', 'ACCOUNT_NUMBER', 'STATUS_ID', 'PRICE', 'CURRENCY'),
            'filter' => array('=ID' => $orderId),
            'limit' => 1,
        ))->fetch();

        if (!$order) {
            throw new \InvalidArgumentException('Заказ #' . $orderId . ' не найден.');
        }

        $statuses = array();
        $rows = \Bitrix\Sale\Internals\StatusTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=TYPE' => 'O'),
        ))->fetchAll();
        foreach ($rows as $row) {
            $statuses[] = (string)$row['ID'];
        }

        if ($statuses !== array() && !in_array($statusId, $statuses, true)) {
            throw new \InvalidArgumentException('Неизвестный статус "' . $statusId . '". Доступны: ' . implode(', ', $statuses));
        }

        return array(
            'summary' => 'Смена статуса заказа #' . $orderId . ' на ' . $statusId,
            'payload' => array(
                'args' => array('order_id' => $orderId, 'status_id' => $statusId),
                'before' => array('status_id' => (string)$order['STATUS_ID']),
            ),
            'preview' => 'Заказ ' . ($order['ACCOUNT_NUMBER'] ?? $orderId) . ': статус '
                . $order['STATUS_ID'] . ' → ' . $statusId,
        );
    }

    public static function apply(array $payload): array
    {
        $orderId = (int)($payload['args']['order_id'] ?? 0);
        $statusId = (string)($payload['args']['status_id'] ?? '');

        $order = Order::load($orderId);
        if (!$order) {
            throw new \RuntimeException('Заказ #' . $orderId . ' не найден.');
        }

        $order->setField('STATUS_ID', $statusId);
        $result = $order->save();

        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        return array('order_id' => $orderId, 'status_id' => $statusId);
    }

    public static function rollback(array $payload): void
    {
        $orderId = (int)($payload['args']['order_id'] ?? 0);
        $previous = (string)($payload['before']['status_id'] ?? '');

        if ($orderId <= 0 || $previous === '') {
            return;
        }

        $order = Order::load($orderId);
        if (!$order) {
            throw new \RuntimeException('Заказ #' . $orderId . ' не найден — откат невозможен.');
        }

        $order->setField('STATUS_ID', $previous);
        $result = $order->save();

        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }
    }
}
