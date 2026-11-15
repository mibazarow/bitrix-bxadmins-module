<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Sale\Order;

/** Заказ целиком: статус, оплата, отмена, комментарий менеджера. */
class UpdateOrderTool extends AbstractChangeTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'update_order';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_order',
            'description' => 'Меняет заказ: статус, отметку об оплате, отмену и комментарий менеджера. Деньги не двигает.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'order_id' => array('type' => 'integer', 'description' => 'ID заказа'),
                    'status_id' => array('type' => 'string', 'description' => 'Код статуса (list_order_statuses)'),
                    'paid' => array('type' => 'boolean', 'description' => 'Отметить как оплаченный'),
                    'canceled' => array('type' => 'boolean', 'description' => 'Отменить заказ'),
                    'comment' => array('type' => 'string', 'description' => 'Комментарий менеджера'),
                ),
                'required' => array('order_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $orderId = (int)($args['order_id'] ?? 0);
        if ($orderId <= 0) {
            throw new \InvalidArgumentException('Параметр order_id обязателен.');
        }

        if (!Loader::includeModule('sale')) {
            throw new \RuntimeException('Модуль sale недоступен.');
        }

        $order = Order::load($orderId);
        if (!$order) {
            throw new \InvalidArgumentException('Заказ #' . $orderId . ' не найден.');
        }

        $changes = array();
        $lines = array();

        if (array_key_exists('status_id', $args)) {
            $status = trim((string)$args['status_id']);
            if ($status !== '' && $status !== (string)$order->getField('STATUS_ID')) {
                $changes['STATUS_ID'] = $status;
                $lines[] = 'Статус: ' . $order->getField('STATUS_ID') . ' → ' . $status;
            }
        }

        if (array_key_exists('paid', $args)) {
            $paid = ((bool)$args['paid']) ? 'Y' : 'N';
            if ($paid !== (string)$order->getField('PAYED')) {
                $changes['PAYED'] = $paid;
                $lines[] = 'Оплата: ' . $order->getField('PAYED') . ' → ' . $paid;
            }
        }

        if (array_key_exists('canceled', $args)) {
            $canceled = ((bool)$args['canceled']) ? 'Y' : 'N';
            if ($canceled !== (string)$order->getField('CANCELED')) {
                $changes['CANCELED'] = $canceled;
                $lines[] = 'Отмена: ' . $order->getField('CANCELED') . ' → ' . $canceled;
            }
        }

        if (array_key_exists('comment', $args)) {
            $comment = mb_substr(trim((string)$args['comment']), 0, 5000);
            if ($comment !== (string)$order->getField('COMMENTS')) {
                $changes['COMMENTS'] = $comment;
                $lines[] = 'Комментарий обновлён';
            }
        }

        if ($changes === array()) {
            throw new \InvalidArgumentException('Изменений нет: переданные значения совпадают с текущими.');
        }

        return array(
            'summary' => 'Заказ #' . $orderId . ': ' . implode(', ', array_keys($changes)),
            'payload' => array(
                'args' => array('order_id' => $orderId, 'changes' => $changes),
                'before' => array(
                    'STATUS_ID' => (string)$order->getField('STATUS_ID'),
                    'PAYED' => (string)$order->getField('PAYED'),
                    'CANCELED' => (string)$order->getField('CANCELED'),
                    'COMMENTS' => (string)$order->getField('COMMENTS'),
                ),
            ),
            'preview' => 'Заказ ' . ($order->getField('ACCOUNT_NUMBER') ?: $orderId) . "\n" . implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $orderId = (int)($payload['args']['order_id'] ?? 0);
        $changes = (array)($payload['args']['changes'] ?? array());

        if ($orderId <= 0 || $changes === array()) {
            throw new \InvalidArgumentException('Нет данных для изменения заказа.');
        }

        $order = Order::load($orderId);
        if (!$order) {
            throw new \RuntimeException('Заказ #' . $orderId . ' не найден.');
        }

        foreach ($changes as $field => $value) {
            $order->setField((string)$field, $value);
        }

        $result = $order->save();
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        return array('order_id' => $orderId, 'updated' => array_keys($changes));
    }

    public static function rollback(array $payload): void
    {
        $orderId = (int)($payload['args']['order_id'] ?? 0);
        $before = (array)($payload['before'] ?? array());

        if ($orderId <= 0 || $before === array()) {
            return;
        }

        $order = Order::load($orderId);
        if (!$order) {
            throw new \RuntimeException('Заказ #' . $orderId . ' не найден — откат невозможен.');
        }

        // Возвращаем только то, что меняли: остальные поля не трогаем.
        $changed = array_keys((array)($payload['args']['changes'] ?? array()));
        foreach ($changed as $field) {
            if (array_key_exists($field, $before)) {
                $order->setField((string)$field, $before[$field]);
            }
        }

        $result = $order->save();
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }
    }
}
