<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Loader;

/**
 * Создание простого товара одним изменением: элемент инфоблока, товарные поля,
 * цена и SEO. Для товара с торговыми предложениями сначала создаётся база,
 * затем предложения через create_offer / update_product_price.
 */
class CreateProductTool extends AbstractChangeTool
{
    public const DOMAIN = 'commerce';

    private const SEO_KEYS = array(
        'seo_title' => 'ELEMENT_META_TITLE',
        'seo_description' => 'ELEMENT_META_DESCRIPTION',
        'seo_keywords' => 'ELEMENT_META_KEYWORDS',
        'seo_h1' => 'ELEMENT_PAGE_TITLE',
    );

    public static function getName(): string
    {
        return 'create_product';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_product',
            'description' => 'Создаёт простой товар за одно изменение: элемент инфоблока, остаток, цену и SEO. Если у инфоблока есть торговые предложения — сначала создай базу этим инструментом, затем create_offer и update_product_price для предложений. Картинки — set_element_images после создания.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'iblock_id' => array('type' => 'integer', 'description' => 'ID инфоблока товаров'),
                    'name' => array('type' => 'string', 'description' => 'Название товара'),
                    'code' => array('type' => 'string', 'description' => 'Символьный код; если не указан — из названия'),
                    'section_id' => array('type' => 'integer', 'description' => 'ID раздела'),
                    'properties' => array('type' => 'object', 'description' => 'Свойства: {"BRAND": "Nike"}'),
                    'active' => array('type' => 'boolean', 'description' => 'Активен, по умолчанию да'),
                    'price' => array('type' => 'number', 'description' => 'Цена продажи; если не указана — цена не создаётся'),
                    'price_type_id' => array('type' => 'integer', 'description' => 'Тип цены, по умолчанию 1 (базовая)'),
                    'currency' => array('type' => 'string', 'description' => 'Валюта, по умолчанию RUB'),
                    'quantity' => array('type' => 'number', 'description' => 'Остаток на складе, по умолчанию 0'),
                    'seo_title' => array('type' => 'string', 'description' => 'Meta title'),
                    'seo_description' => array('type' => 'string', 'description' => 'Meta description'),
                    'seo_keywords' => array('type' => 'string', 'description' => 'Meta keywords'),
                    'seo_h1' => array('type' => 'string', 'description' => 'Заголовок H1'),
                ),
                'required' => array('iblock_id', 'name'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $iblock = IblockElementFields::loadIblock((int)($args['iblock_id'] ?? 0));

        if (Loader::includeModule('catalog')) {
            $offerInfo = \CCatalogSKU::GetInfoByOfferIBlock($iblock['id']);
            if (is_array($offerInfo) && !empty($offerInfo)) {
                throw new \InvalidArgumentException(
                    'Инфоблок «' . $iblock['name'] . '» — это инфоблок торговых предложений. '
                    . 'Товар создаётся в родительском инфоблоке, а предложение — через create_offer.'
                );
            }
        }

        $elementArgs = array(
            'iblock_id' => $iblock['id'],
            'name' => $args['name'] ?? '',
            'code' => $args['code'] ?? '',
            'section_id' => $args['section_id'] ?? 0,
            'properties' => $args['properties'] ?? array(),
            'active' => $args['active'] ?? true,
            'preview_text' => $args['preview_text'] ?? '',
            'detail_text' => $args['detail_text'] ?? '',
            'preview_text_type' => $args['preview_text_type'] ?? 'text',
            'detail_text_type' => $args['detail_text_type'] ?? 'text',
        );

        $base = CreateIblockElementTool::preview($elementArgs);
        $fields = (array)($base['payload']['args'] ?? array());

        $price = isset($args['price']) ? (float)$args['price'] : 0.0;
        if ($price < 0) {
            throw new \InvalidArgumentException('Цена не может быть отрицательной.');
        }

        $priceTypeId = max(0, (int)($args['price_type_id'] ?? 1)) ?: 1;
        $currency = strtoupper(trim((string)($args['currency'] ?? 'RUB'))) ?: 'RUB';
        $quantity = max(0.0, (float)($args['quantity'] ?? 0));

        $templates = array();
        foreach (self::SEO_KEYS as $arg => $template) {
            if (array_key_exists($arg, $args) && trim((string)$args[$arg]) !== '') {
                $templates[$template] = trim((string)$args[$arg]);
            }
        }

        $lines = array($base['preview']);

        if (Loader::includeModule('catalog')) {
            $productInfo = \CCatalogSKU::GetInfoByProductIBlock($iblock['id']);
            if (is_array($productInfo) && !empty($productInfo)) {
                $lines[] = 'Внимание: у инфоблока есть торговые предложения — цена базового товара не используется. '
                    . 'После создания добавь предложения через create_offer и задай цены им.';
            }
        }

        if ($price > 0) {
            $lines[] = 'Цена: ' . number_format($price, 2, '.', ' ') . ' ' . $currency . ' (тип ' . $priceTypeId . ')';
        } else {
            $lines[] = 'Цена: не задана';
        }
        $lines[] = 'Остаток: ' . $quantity;
        if ($templates !== array()) {
            $lines[] = 'SEO: ' . implode(', ', array_keys($templates));
        }

        return array(
            'summary' => 'Создать товар «' . (string)($args['name'] ?? '') . '» в «' . $iblock['name'] . '»',
            'payload' => array(
                'args' => array(
                    'fields' => $fields,
                    'iblock_id' => $iblock['id'],
                    'price' => $price,
                    'price_type_id' => $priceTypeId,
                    'currency' => $currency,
                    'quantity' => $quantity,
                    'templates' => $templates,
                ),
                'before' => array(),
            ),
            'preview' => implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $fields = (array)($args['fields'] ?? array());

        if ($fields === array()) {
            throw new \InvalidArgumentException('Нет полей для создания товара.');
        }

        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            throw new \RuntimeException('Модули iblock/catalog недоступны.');
        }

        $element = new \CIBlockElement();
        $elementId = (int)$element->Add($fields);

        if ($elementId <= 0) {
            throw new \InvalidArgumentException(
                IblockElementFields::legacyError($element, 'Не удалось создать элемент товара.')
            );
        }

        try {
            $product = ProductTable::add(array(
                'ID' => $elementId,
                'QUANTITY' => (float)($args['quantity'] ?? 0),
                'QUANTITY_TRACE' => 'Y',
                'CAN_BUY_ZERO' => 'N',
            ));

            if (!$product->isSuccess()) {
                throw new \RuntimeException('Не удалось создать товарные поля: ' . implode('; ', $product->getErrorMessages()));
            }

            $priceId = 0;
            $price = (float)($args['price'] ?? 0);

            if ($price > 0) {
                $priceResult = PriceTable::add(array(
                    'PRODUCT_ID' => $elementId,
                    'CATALOG_GROUP_ID' => (int)($args['price_type_id'] ?? 1),
                    'PRICE' => $price,
                    'PRICE_SCALE' => $price,
                    'CURRENCY' => (string)($args['currency'] ?? 'RUB'),
                ));

                if (!$priceResult->isSuccess()) {
                    throw new \RuntimeException('Не удалось создать цену: ' . implode('; ', $priceResult->getErrorMessages()));
                }

                $priceId = (int)$priceResult->getId();
            }

            $templates = (array)($args['templates'] ?? array());
            if ($templates !== array()) {
                $updater = new \CIBlockElement();
                if (!$updater->Update($elementId, array('IPROPERTY_TEMPLATES' => $templates))) {
                    throw new \RuntimeException(
                        IblockElementFields::legacyError($updater, 'Товар создан, но SEO-поля не сохранились.')
                    );
                }
            }
        } catch (\Throwable $e) {
            self::cleanup($elementId);

            throw $e;
        }

        return array(
            'element_id' => $elementId,
            'product_id' => $elementId,
            'price_id' => $priceId,
            'name' => (string)($fields['NAME'] ?? ''),
        );
    }

    public static function rollback(array $payload): void
    {
        $elementId = (int)($payload['result']['element_id'] ?? 0);
        $priceId = (int)($payload['result']['price_id'] ?? 0);

        if ($priceId > 0) {
            PriceTable::delete($priceId);
        }

        if ($elementId > 0) {
            self::cleanup($elementId);
        }
    }

    private static function cleanup(int $elementId): void
    {
        if ($elementId <= 0) {
            return;
        }

        try {
            ProductTable::delete($elementId);
        } catch (\Throwable $e) {
            // Товар мог не создаться — удаление элемента ниже всё равно выполнится.
        }

        \CIBlockElement::Delete($elementId);
    }
}
