<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Loader;

/** Товары каталога. */
class ListCatalogProductsTool extends AbstractTool
{
    public const DOMAIN = 'commerce';

    private const LIMIT = 100;

    public static function getName(): string
    {
        return 'list_catalog_products';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_catalog_products',
            'description' => 'Товары каталога: ID элемента, тип, доступное количество, возможность покупки. Максимум 100.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'offset' => array('type' => 'integer', 'description' => 'Пропустить N записей (для следующей страницы)'),
                    'iblock_id' => array('type' => 'integer', 'description' => 'Только товары этого инфоблока'),
                    'available_only' => array('type' => 'boolean', 'description' => 'Только доступные к покупке'),
                ),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,products:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('catalog')) {
            return array('error' => 'Модуль catalog недоступен — редакция не магазинная.');
        }

        $filter = array();
        if (($args['available_only'] ?? false) === true) {
            $filter['=AVAILABLE'] = 'Y';
        }

        $rows = ProductTable::getList(array(
            'select' => array('ID', 'TYPE', 'AVAILABLE', 'QUANTITY', 'QUANTITY_TRACE', 'CAN_BUY_ZERO'),
            'filter' => $filter,
            'order' => array('ID' => 'DESC'),
            'offset' => max(0, (int)($args['offset'] ?? 0)),
            'limit' => self::LIMIT + 1,
        ))->fetchAll();

        $truncated = count($rows) > self::LIMIT;
        $products = array();

        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            $products[] = array(
                'element_id' => (int)$row['ID'],
                'type' => (int)$row['TYPE'],
                'available' => (string)$row['AVAILABLE'] === 'Y',
                'quantity' => (float)$row['QUANTITY'],
                'quantity_trace' => (string)$row['QUANTITY_TRACE'] === 'Y',
                'can_buy_zero' => (string)$row['CAN_BUY_ZERO'] === 'Y',
            );
        }

        return array('count' => count($products), 'truncated' => $truncated, 'products' => $products);
    }
}
