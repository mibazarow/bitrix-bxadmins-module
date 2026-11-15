<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Catalog\PriceTable;
use Bitrix\Main\Loader;
use Mibazarow\Bxadmins\Service\CatalogOffers;

/** Изменение цены товара. */
class UpdateProductPriceTool extends AbstractChangeTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'update_product_price';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_product_price',
            'description' => 'Меняет цену товара для указанного типа цен. Если у товара есть торговые предложения — цену базы не меняет, а просит указать ID предложения. Деньги не списывает — только прайс.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'product_id' => array('type' => 'integer', 'description' => 'ID товара'),
                    'price_type_id' => array('type' => 'integer', 'description' => 'ID типа цен (1 — базовая)'),
                    'price' => array('type' => 'number', 'description' => 'Новая цена'),
                    'currency' => array('type' => 'string', 'description' => 'Валюта, по умолчанию из текущей цены'),
                ),
                'required' => array('product_id', 'price_type_id', 'price'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $productId = (int)($args['product_id'] ?? 0);
        $priceTypeId = (int)($args['price_type_id'] ?? 0);
        $price = (float)($args['price'] ?? -1);

        if ($productId <= 0 || $priceTypeId <= 0 || $price < 0) {
            throw new \InvalidArgumentException('Нужны product_id, price_type_id и неотрицательная price.');
        }

        if (!Loader::includeModule('catalog')) {
            throw new \RuntimeException('Модуль catalog недоступен.');
        }

        // Цена базы и цена предложения — разные записи. Не угадываем и не меняем базу.
        $offers = CatalogOffers::offersFor($productId);
        if ($offers !== array()) {
            throw new \InvalidArgumentException(
                'У товара #' . $productId . ' есть торговые предложения: ' . CatalogOffers::hint($offers)
                . '. Цену базового товара не меняю. Спроси пользователя, какое предложение менять (или все), '
                . 'и передай ID предложения как product_id. Список — list_offers.'
            );
        }

        $existing = PriceTable::getList(array(
            'select' => array('ID', 'PRICE', 'CURRENCY'),
            'filter' => array('=PRODUCT_ID' => $productId, '=CATALOG_GROUP_ID' => $priceTypeId),
            'limit' => 1,
        ))->fetch();

        $currency = trim((string)($args['currency'] ?? ''))
            ?: (string)($existing['CURRENCY'] ?? 'RUB');

        return array(
            'summary' => 'Цена товара #' . $productId . ' (тип ' . $priceTypeId . ')',
            'payload' => array(
                'args' => array(
                    'product_id' => $productId,
                    'price_type_id' => $priceTypeId,
                    'price' => $price,
                    'currency' => $currency,
                    'price_id' => (int)($existing['ID'] ?? 0),
                ),
                'before' => array(
                    'exists' => $existing !== false,
                    'price' => (float)($existing['PRICE'] ?? 0),
                    'currency' => (string)($existing['CURRENCY'] ?? ''),
                ),
            ),
            'preview' => 'Товар #' . $productId . ': '
                . ($existing
                    ? number_format((float)$existing['PRICE'], 2, '.', ' ') . ' ' . $existing['CURRENCY']
                    : '(цены нет)')
                . ' → ' . number_format($price, 2, '.', ' ') . ' ' . $currency,
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $priceId = (int)($args['price_id'] ?? 0);

        $fields = array(
            'PRODUCT_ID' => (int)$args['product_id'],
            'CATALOG_GROUP_ID' => (int)$args['price_type_id'],
            'PRICE' => (float)$args['price'],
            // Базовая цена нужна ядру каталога при создании записи о цене.
            'PRICE_SCALE' => (float)$args['price'],
            'CURRENCY' => (string)$args['currency'],
        );

        if ($priceId > 0) {
            $result = PriceTable::update($priceId, $fields);
        } else {
            $result = PriceTable::add($fields);
        }

        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        return array('price_id' => (int)$result->getId(), 'product_id' => $fields['PRODUCT_ID']);
    }

    public static function rollback(array $payload): void
    {
        $args = (array)($payload['args'] ?? array());
        $before = (array)($payload['before'] ?? array());
        $priceId = (int)($args['price_id'] ?? 0);

        if ($priceId <= 0) {
            // Цены не было — удаляем созданную.
            $createdId = (int)($payload['result']['price_id'] ?? 0);
            if ($createdId > 0) {
                PriceTable::delete($createdId);
            }

            return;
        }

        if (empty($before['exists'])) {
            PriceTable::delete($priceId);

            return;
        }

        PriceTable::update($priceId, array(
            'PRICE' => (float)$before['price'],
            'PRICE_SCALE' => (float)$before['price'],
            'CURRENCY' => (string)$before['currency'],
        ));
    }
}
