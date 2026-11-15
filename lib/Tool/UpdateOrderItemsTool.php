<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Catalog\PriceTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Sale\Order;

/**
 * Состав заказа: добавить товар, убрать позицию, изменить количество.
 *
 * Почему напрямую в таблицу корзины. Штатный D7-путь (`Basket::createItem` +
 * `setField('QUANTITY')`) для заказа с пустой корзиной упирается в проверку
 * доступности провайдера: без доверенных данных из формы админки он отвечает
 * «товар недоступен», а `setField` при этом молча ничего не меняет.
 * Легаси-исключение: пишем строку корзины и пересчитываем заказ через
 * `doFinalAction()` — суммы, скидки и налоги пересчитывает ядро.
 *
 * Снимок всех строк корзины сохраняется, откат возвращает их с прежними ID.
 */
class UpdateOrderItemsTool extends AbstractChangeTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'update_order_items';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_order_items',
            'description' => 'Меняет состав заказа: добавляет товар, убирает позицию или меняет количество. Сумма пересчитывается. Позиции ищутся по basket_id — он есть в list_orders.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'order_id' => array('type' => 'integer', 'description' => 'ID заказа'),
                    'add' => array(
                        'type' => 'array',
                        'description' => 'Добавить товары: [{"product_id": 123, "quantity": 2}]',
                        'items' => array(
                            'type' => 'object',
                            'properties' => array(
                                'product_id' => array('type' => 'integer'),
                                'quantity' => array('type' => 'number'),
                            ),
                            'required' => array('product_id'),
                        ),
                    ),
                    'remove' => array(
                        'type' => 'array',
                        'items' => array('type' => 'integer'),
                        'description' => 'ID позиций корзины, которые убрать',
                    ),
                    'change' => array(
                        'type' => 'array',
                        'description' => 'Изменить количество: [{"basket_id": 5, "quantity": 3}]',
                        'items' => array(
                            'type' => 'object',
                            'properties' => array(
                                'basket_id' => array('type' => 'integer'),
                                'quantity' => array('type' => 'number'),
                            ),
                            'required' => array('basket_id', 'quantity'),
                        ),
                    ),
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

        if (!Loader::includeModule('sale') || !Loader::includeModule('catalog')) {
            throw new \RuntimeException('Модули sale и catalog должны быть установлены.');
        }

        $order = Order::load($orderId);
        if (!$order) {
            throw new \InvalidArgumentException('Заказ #' . $orderId . ' не найден.');
        }

        $rows = self::basketRows($orderId);
        $lines = array();

        $byId = array();
        foreach ($rows as $row) {
            $byId[(int)$row['ID']] = $row;
        }

        foreach ((array)($args['remove'] ?? array()) as $basketId) {
            $basketId = (int)$basketId;

            if (!isset($byId[$basketId])) {
                throw new \InvalidArgumentException(
                    'В заказе нет позиции #' . $basketId . '. Есть: ' . implode(', ', array_keys($byId))
                );
            }

            $lines[] = 'Убрать: ' . (string)$byId[$basketId]['NAME'];
        }

        foreach ((array)($args['change'] ?? array()) as $change) {
            if (!is_array($change)) {
                continue;
            }

            $basketId = (int)($change['basket_id'] ?? 0);
            $quantity = (float)($change['quantity'] ?? 0);

            if (!isset($byId[$basketId])) {
                throw new \InvalidArgumentException(
                    'В заказе нет позиции #' . $basketId . '. Есть: ' . implode(', ', array_keys($byId))
                );
            }

            if ($quantity <= 0) {
                throw new \InvalidArgumentException('Количество должно быть больше нуля.');
            }

            $lines[] = 'Количество «' . (string)$byId[$basketId]['NAME'] . '»: '
                . (float)$byId[$basketId]['QUANTITY'] . ' → ' . $quantity;
        }

        foreach ((array)($args['add'] ?? array()) as $add) {
            if (!is_array($add) || (int)($add['product_id'] ?? 0) <= 0) {
                continue;
            }

            $productId = (int)$add['product_id'];
            $quantity = (float)($add['quantity'] ?? 1);

            $product = self::product($productId);
            if ($product === null) {
                throw new \InvalidArgumentException('Товар #' . $productId . ' не найден в каталоге.');
            }

            $lines[] = 'Добавить: ' . $product['NAME'] . ' × ' . $quantity
                . ' по ' . number_format($product['PRICE'], 2, '.', ' ') . ' ' . $product['CURRENCY'];
        }

        if ($lines === array()) {
            throw new \InvalidArgumentException('Не указано, что менять: add, remove или change.');
        }

        return array(
            'summary' => 'Состав заказа #' . $orderId,
            'payload' => array(
                'args' => array(
                    'order_id' => $orderId,
                    'add' => (array)($args['add'] ?? array()),
                    'remove' => (array)($args['remove'] ?? array()),
                    'change' => (array)($args['change'] ?? array()),
                ),
                'before' => array('rows' => $rows),
            ),
            'preview' => 'Заказ ' . ($order->getField('ACCOUNT_NUMBER') ?: $orderId) . ":\n" . implode("\n", $lines)
                . "\n\nСумма заказа пересчитается автоматически.",
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $orderId = (int)$args['order_id'];

        $order = Order::load($orderId);
        if (!$order) {
            throw new \RuntimeException('Заказ #' . $orderId . ' не найден.');
        }

        $connection = Application::getConnection();
        $table = self::table();

        foreach ((array)($args['remove'] ?? array()) as $basketId) {
            $connection->queryExecute('DELETE FROM ' . $table . ' WHERE ID = ' . (int)$basketId
                . ' AND ORDER_ID = ' . $orderId);
        }

        foreach ((array)($args['change'] ?? array()) as $change) {
            if (!is_array($change)) {
                continue;
            }

            $quantity = (float)($change['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $connection->queryExecute(
                'UPDATE ' . $table . ' SET QUANTITY = ' . number_format($quantity, 4, '.', '')
                . ' WHERE ID = ' . (int)($change['basket_id'] ?? 0) . ' AND ORDER_ID = ' . $orderId
            );
        }

        foreach ((array)($args['add'] ?? array()) as $add) {
            if (!is_array($add) || (int)($add['product_id'] ?? 0) <= 0) {
                continue;
            }

            self::insertRow($order, (int)$add['product_id'], (float)($add['quantity'] ?? 1));
        }

        self::recalculate($orderId);

        $order = Order::load($orderId);

        return array(
            'order_id' => $orderId,
            'total' => (float)$order->getPrice(),
            'items' => count(self::basketRows($orderId)),
        );
    }

    public static function rollback(array $payload): void
    {
        $orderId = (int)($payload['args']['order_id'] ?? 0);
        $snapshot = (array)($payload['before']['rows'] ?? array());

        if ($orderId <= 0) {
            return;
        }

        $connection = Application::getConnection();
        $table = self::table();

        $current = array();
        foreach (self::basketRows($orderId) as $row) {
            $current[(int)$row['ID']] = $row;
        }

        $snapshotIds = array();

        foreach ($snapshot as $row) {
            $id = (int)$row['ID'];
            $snapshotIds[$id] = true;

            if (isset($current[$id])) {
                // Позиция на месте — возвращаем прежнее количество и цены.
                $connection->queryExecute(
                    'UPDATE ' . $table
                    . ' SET QUANTITY = ' . number_format((float)$row['QUANTITY'], 4, '.', '')
                    . ', PRICE = ' . number_format((float)$row['PRICE'], 4, '.', '')
                    . ', BASE_PRICE = ' . number_format((float)$row['BASE_PRICE'], 4, '.', '')
                    . ' WHERE ID = ' . $id
                );

                continue;
            }

            // Позицию удалили — вставляем обратно с тем же ID.
            unset($row['DATE_INSERT'], $row['DATE_UPDATE']);
            $row['DATE_INSERT'] = new \Bitrix\Main\Type\DateTime();
            $row['DATE_UPDATE'] = new \Bitrix\Main\Type\DateTime();
            $connection->add($table, $row);
        }

        // Позиции, которых не было в снимке, — добавленные; убираем.
        foreach ($current as $id => $row) {
            if (!isset($snapshotIds[$id])) {
                $connection->queryExecute('DELETE FROM ' . $table . ' WHERE ID = ' . $id);
            }
        }

        self::recalculate($orderId);
    }

    /** Пересчёт сумм заказа: ядро пересчитывает итоги из корзины. */
    private static function recalculate(int $orderId): void
    {
        $order = Order::load($orderId);
        if (!$order) {
            throw new \RuntimeException('Заказ #' . $orderId . ' не найден при пересчёте.');
        }

        $order->doFinalAction(true);

        $result = $order->save();
        if (!$result->isSuccess()) {
            throw new \RuntimeException(
                'Не удалось пересчитать заказ: ' . implode('; ', $result->getErrorMessages())
            );
        }
    }

    /** @return array<int,array> строки корзины заказа целиком */
    private static function basketRows(int $orderId): array
    {
        $rows = Application::getConnection()->query(
            'SELECT * FROM ' . self::table() . ' WHERE ORDER_ID = ' . $orderId . ' ORDER BY ID'
        )->fetchAll();

        return is_array($rows) ? $rows : array();
    }

    private static function insertRow(Order $order, int $productId, float $quantity): void
    {
        $product = self::product($productId);
        if ($product === null) {
            throw new \InvalidArgumentException('Товар #' . $productId . ' не найден в каталоге.');
        }

        $connection = Application::getConnection();

        // FUSER_ID — запись b_sale_fuser, а не ID пользователя.
        $fuserId = (int)\Bitrix\Sale\Fuser::getIdByUserId((int)$order->getUserId());
        if ($fuserId <= 0) {
            $fuserId = (int)$connection->queryScalar(
                'SELECT FUSER_ID FROM ' . self::table() . ' WHERE ORDER_ID = ' . (int)$order->getId() . ' LIMIT 1'
            );
        }

        $now = new \Bitrix\Main\Type\DateTime();

        $connection->add(self::table(), array(
            'FUSER_ID' => $fuserId,
            'ORDER_ID' => (int)$order->getId(),
            'PRODUCT_ID' => $productId,
            'PRODUCT_PRICE_ID' => $product['PRICE_ID'],
            'PRICE' => $product['PRICE'],
            'BASE_PRICE' => $product['PRICE'],
            'CURRENCY' => $product['CURRENCY'],
            'QUANTITY' => $quantity > 0 ? $quantity : 1,
            'LID' => $order->getSiteId(),
            'NAME' => $product['NAME'],
            'MODULE' => 'catalog',
            'PRODUCT_PROVIDER_CLASS' => '\\Bitrix\\Catalog\\Product\\CatalogProvider',
            'CAN_BUY' => 'Y',
            'DELAY' => 'N',
            'DATE_INSERT' => $now,
            'DATE_UPDATE' => $now,
        ));
    }

    /**
     * @return array{NAME:string,PRICE:float,PRICE_ID:int,CURRENCY:string}|null
     */
    private static function product(int $productId): ?array
    {
        $element = ElementTable::getList(array(
            'select' => array('ID', 'NAME'),
            'filter' => array('=ID' => $productId),
            'limit' => 1,
        ))->fetch();

        if (!$element) {
            return null;
        }

        $price = PriceTable::getList(array(
            'select' => array('ID', 'PRICE', 'CURRENCY'),
            'filter' => array('=PRODUCT_ID' => $productId),
            'order' => array('CATALOG_GROUP_ID' => 'ASC'),
            'limit' => 1,
        ))->fetch();

        if (!$price) {
            return null;
        }

        return array(
            'NAME' => (string)$element['NAME'],
            'PRICE' => (float)$price['PRICE'],
            'PRICE_ID' => (int)$price['ID'],
            'CURRENCY' => (string)$price['CURRENCY'],
        );
    }

    private static function table(): string
    {
        return \Bitrix\Sale\Internals\BasketTable::getTableName();
    }
}
