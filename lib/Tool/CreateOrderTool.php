<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Loader;
use Bitrix\Sale\Basket;
use Bitrix\Sale\Order;

/**
 * Создание заказа. Деньги не списываются: заказ создаётся в статусе,
 * который задан по умолчанию, оплата не инициируется.
 */
class CreateOrderTool extends AbstractChangeTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'create_order';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_order',
            'description' => 'Создаёт заказ для пользователя с товарами. Оплата не запускается, деньги не списываются.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'user_id' => array('type' => 'integer', 'description' => 'ID покупателя'),
                    'items' => array(
                        'type' => 'array',
                        'description' => 'Позиции: [{"product_id": 632, "quantity": 2}]',
                        'items' => array(
                            'type' => 'object',
                            'properties' => array(
                                'product_id' => array('type' => 'integer'),
                                'quantity' => array('type' => 'number'),
                            ),
                        ),
                    ),
                    'currency' => array('type' => 'string', 'description' => 'Валюта, по умолчанию базовая'),
                    'comment' => array('type' => 'string', 'description' => 'Комментарий покупателя'),
                ),
                'required' => array('user_id', 'items'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $userId = (int)($args['user_id'] ?? 0);
        $items = is_array($args['items'] ?? null) ? $args['items'] : array();

        if ($userId <= 0) {
            throw new \InvalidArgumentException('Параметр user_id обязателен.');
        }

        if ($items === array()) {
            throw new \InvalidArgumentException('Нужна хотя бы одна позиция.');
        }

        if (!Loader::includeModule('sale') || !Loader::includeModule('catalog')) {
            throw new \RuntimeException('Модули sale/catalog недоступны — редакция не магазинная.');
        }

        $normalized = array();
        $lines = array();
        $total = 0.0;

        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $quantity = (float)($item['quantity'] ?? 1);

            if ($productId <= 0 || $quantity <= 0) {
                throw new \InvalidArgumentException('У позиции нужны product_id и quantity > 0.');
            }

            if (!ProductTable::getById($productId)->fetch()) {
                throw new \InvalidArgumentException('Товар #' . $productId . ' не найден в каталоге.');
            }

            $normalized[] = array('product_id' => $productId, 'quantity' => $quantity);
            $lines[] = 'товар #' . $productId . ' × ' . $quantity;
            $total += $quantity;
        }

        $currency = strtoupper(trim((string)($args['currency'] ?? '')));

        return array(
            'summary' => 'Заказ для пользователя #' . $userId,
            'payload' => array(
                'args' => array(
                    'user_id' => $userId,
                    'items' => $normalized,
                    'currency' => $currency,
                    'comment' => trim((string)($args['comment'] ?? '')),
                ),
            ),
            'preview' => 'Новый заказ для пользователя #' . $userId . ':' . "\n" . implode("\n", $lines)
                . "\nОплата не запускается.",
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $userId = (int)$args['user_id'];

        $siteId = defined('SITE_ID') ? SITE_ID : 's1';
        $currency = (string)($args['currency'] ?? '');

        if ($currency === '') {
            $currency = (string)\Bitrix\Currency\CurrencyManager::getBaseCurrency();
        }

        $order = Order::create($siteId, $userId);
        $order->setPersonTypeId(self::personTypeId($siteId));
        $order->setField('CURRENCY', $currency);

        $comment = (string)($args['comment'] ?? '');
        if ($comment !== '') {
            $order->setField('USER_DESCRIPTION', $comment);
        }

        $basket = Basket::create($siteId);
        $order->setBasket($basket);

        foreach ((array)$args['items'] as $item) {
            $productId = (int)$item['product_id'];
            $quantity = (float)$item['quantity'];

            $basketItem = $basket->createItem('catalog', $productId);
            $basketItem->setFields(array(
                'QUANTITY' => $quantity,
                'CURRENCY' => $currency,
                'LID' => $siteId,
                'PRODUCT_PROVIDER_CLASS' => '\\Bitrix\\Catalog\\Product\\CatalogProvider',
            ));
        }

        $result = $order->save();

        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        return array(
            'order_id' => (int)$order->getId(),
            'account_number' => (string)$order->getField('ACCOUNT_NUMBER'),
            'price' => (float)$order->getPrice(),
            'currency' => $currency,
        );
    }

    public static function rollback(array $payload): void
    {
        $orderId = (int)($payload['result']['order_id'] ?? 0);
        if ($orderId <= 0) {
            return;
        }

        // Удаление заказа — статический метод сущности; экземплярный delete()
        // в этой версии требует аргумент и не подходит.
        $result = Order::delete($orderId);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }
    }

    private static function personTypeId(string $siteId): int
    {
        $row = \Bitrix\Sale\Internals\PersonTypeTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=ACTIVE' => 'Y', '=PERSON_TYPE_SITE.SITE_ID' => $siteId),
            'order' => array('SORT' => 'ASC'),
            'limit' => 1,
        ))->fetch();

        if (!$row) {
            $row = \Bitrix\Sale\Internals\PersonTypeTable::getList(array(
                'select' => array('ID'),
                'filter' => array('=ACTIVE' => 'Y'),
                'order' => array('SORT' => 'ASC'),
                'limit' => 1,
            ))->fetch();
        }

        return (int)($row['ID'] ?? 0);
    }
}
