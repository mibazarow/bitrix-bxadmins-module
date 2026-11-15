<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Catalog\ProductTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Loader;

/**
 * Товары и предложения с остатком не выше порога. Только чтение.
 */
class LowStockTool extends AbstractTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'low_stock';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'low_stock',
            'description' => 'Показывает товары и торговые предложения с остатком не выше порога: название, ID, остаток, резерв, учёт количества. Только чтение.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'threshold' => array('type' => 'number', 'description' => 'Порог остатка, по умолчанию 3'),
                    'limit' => array('type' => 'integer', 'description' => 'Сколько позиций вернуть, по умолчанию 50, максимум 200'),
                    'iblock_id' => array('type' => 'integer', 'description' => 'Ограничить инфоблоком (товары или предложения)'),
                ),
            ),
        );
    }

    /** @return array<string,mixed>|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('catalog') || !Loader::includeModule('iblock')) {
            return array('error' => 'Модули catalog/iblock недоступны — редакция не магазинная.');
        }

        $threshold = (float)($args['threshold'] ?? 3);
        $limit = max(1, min(200, (int)($args['limit'] ?? 50)));
        $iblockId = (int)($args['iblock_id'] ?? 0);

        $filter = array('<=QUANTITY' => $threshold);
        if ($iblockId > 0) {
            $elements = ElementTable::getList(array(
                'select' => array('ID'),
                'filter' => array('=IBLOCK_ID' => $iblockId),
                'limit' => 5000,
            ))->fetchAll();
            $ids = array_map(static function (array $row): int {
                return (int)$row['ID'];
            }, $elements);

            if ($ids === array()) {
                return array('threshold' => $threshold, 'count' => 0, 'products' => array());
            }

            $filter['=ID'] = $ids;
        }

        $rows = ProductTable::getList(array(
            'select' => array('ID', 'QUANTITY', 'QUANTITY_RESERVED', 'QUANTITY_TRACE', 'CAN_BUY_ZERO'),
            'filter' => $filter,
            'order' => array('QUANTITY' => 'ASC', 'ID' => 'ASC'),
            'limit' => $limit,
        ))->fetchAll();

        $elementIds = array_map(static function (array $row): int {
            return (int)$row['ID'];
        }, $rows);

        $elements = array();
        if ($elementIds !== array()) {
            $elementRows = ElementTable::getList(array(
                'select' => array('ID', 'NAME', 'IBLOCK_ID', 'CODE', 'ACTIVE'),
                'filter' => array('=ID' => $elementIds),
                'limit' => count($elementIds),
            ))->fetchAll();

            foreach ($elementRows as $row) {
                $elements[(int)$row['ID']] = $row;
            }
        }

        $products = array();
        foreach ($rows as $row) {
            $id = (int)$row['ID'];
            $element = $elements[$id] ?? null;

            if ($element === null || (string)($element['ACTIVE'] ?? 'N') !== 'Y') {
                continue;
            }

            $quantity = (float)$row['QUANTITY'];
            $reserved = (float)($row['QUANTITY_RESERVED'] ?? 0);

            $products[] = array(
                'product_id' => $id,
                'name' => (string)$element['NAME'],
                'iblock_id' => (int)$element['IBLOCK_ID'],
                'code' => (string)($element['CODE'] ?? ''),
                'quantity' => round($quantity, 3),
                'reserved' => round($reserved, 3),
                'available' => round($quantity - $reserved, 3),
                'quantity_trace' => (string)($row['QUANTITY_TRACE'] ?? ''),
                'can_buy_zero' => (string)($row['CAN_BUY_ZERO'] ?? ''),
            );
        }

        return array(
            'threshold' => $threshold,
            'count' => count($products),
            'products' => $products,
            'note' => 'В списке и товары, и торговые предложения: остаток хранится у каждого ID отдельно.',
        );
    }
}
