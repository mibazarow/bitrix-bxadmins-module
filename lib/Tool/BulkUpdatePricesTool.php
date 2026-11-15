<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Catalog\PriceTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\SectionElementTable;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Mibazarow\Bxadmins\Service\CatalogOffers;

/**
 * Массовое изменение цен: процент, надбавка или фиксированное значение.
 *
 * Все изменённые цены сохраняются в снимке и возвращаются при откате, поэтому
 * операция остаётся одной точкой отката, как и одиночная правка.
 */
class BulkUpdatePricesTool extends AbstractChangeTool
{
    public const DOMAIN = 'commerce';

    /** Предохранитель: больше не берём, чтобы не уронить сайт и не «случайно всё». */
    private const MAX_ITEMS = 20000;

    public static function getName(): string
    {
        return 'bulk_update_prices';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'bulk_update_prices',
            'description' => 'Меняет цены у группы товаров: на процент, на сумму или ставит фиксированную. Фильтр — инфоблок, раздел (с подразделами) или список ID; без фильтра — все товары всех каталогов. До 20 000 товаров за раз: подтверждение и откат — одни на всю операцию, дробить не нужно.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'price_type_id' => array('type' => 'integer', 'description' => 'ID типа цены (list_price_types), по умолчанию базовый'),
                    'iblock_id' => array('type' => 'integer', 'description' => 'Ограничить инфоблоком'),
                    'section_id' => array('type' => 'integer', 'description' => 'Ограничить разделом (включая подразделы)'),
                    'element_ids' => array(
                        'type' => 'array',
                        'items' => array('type' => 'integer'),
                        'description' => 'Конкретные товары вместо фильтра',
                    ),
                    'mode' => array(
                        'type' => 'string',
                        'description' => 'percent (на процент), delta (на сумму), set (поставить значение)',
                    ),
                    'value' => array('type' => 'number', 'description' => 'На сколько или какое значение'),
                    'round_to' => array('type' => 'number', 'description' => 'Округлить до, по умолчанию 0.01'),
                    'dry_run' => array('type' => 'boolean', 'description' => 'Только показать, что изменится'),
                ),
                'required' => array('mode', 'value'),
            ),
        );
    }

    /** Пробный прогон: показываем расчёт, но изменение не создаём. */
    public static function execute(array $args, array $context = array()): array
    {
        if (empty($args['dry_run'])) {
            return parent::execute($args, $context);
        }

        $prepared = static::preview($args);

        return array(
            'ok' => true,
            'dry_run' => true,
            'executed' => false,
            'summary' => (string)$prepared['summary'],
            'preview' => (string)$prepared['preview'],
        );
    }

    public static function preview(array $args): array
    {
        if (!Loader::includeModule('catalog')) {
            throw new \RuntimeException('Модуль catalog не установлен.');
        }

        $mode = self::mode($args);
        $value = (float)($args['value'] ?? 0);

        if ($mode === 'percent' && $value === 0.0) {
            throw new \InvalidArgumentException('Процент не может быть нулевым.');
        }

        $priceTypeId = self::priceTypeId($args);
        $targets = self::targets($args, $priceTypeId);

        // Товары с предложениями: базу массово не трогаем — это почти всегда «не те» цены.
        $baseWithOffers = CatalogOffers::baseProductsWithOffers(
            array_map(static fn (array $row): int => (int)$row['PRODUCT_ID'], $targets)
        );

        $skipped = 0;
        if ($baseWithOffers !== array()) {
            if (!empty($args['element_ids'])) {
                throw new \InvalidArgumentException(
                    'Среди выбранных товаров есть товар с торговыми предложениями ('
                    . CatalogOffers::hint(array_keys($baseWithOffers))
                    . '). Уточни, менять ли цену базы или конкретного предложения (element_ids = ID предложений).'
                );
            }

            $targets = array_values(array_filter($targets, static function (array $row) use ($baseWithOffers): bool {
                return !isset($baseWithOffers[(int)$row['PRODUCT_ID']]);
            }));
            $skipped = count($baseWithOffers);

            if ($targets === array()) {
                throw new \InvalidArgumentException('Все товары под фильтром имеют торговые предложения — базу не меняю.');
            }
        }

        if (count($targets) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException(
                'Под фильтр попадает ' . count($targets) . ' товаров — больше предела ' . self::MAX_ITEMS
                . '. Сузьте фильтр (раздел, инфоблок или список ID).'
            );
        }

        $round = (float)($args['round_to'] ?? 0.01);
        $before = array();
        $sample = array();
        $changed = 0;

        foreach ($targets as $row) {
            $old = (float)$row['PRICE'];
            $new = self::calculate($old, $mode, $value, $round);

            if ($new === $old) {
                continue;
            }

            $before[(int)$row['ID']] = $old;
            $changed++;

            if (count($sample) < 5) {
                $sample[] = '#' . (int)$row['PRODUCT_ID'] . ': ' . number_format($old, 2, '.', ' ')
                    . ' → ' . number_format($new, 2, '.', ' ');
            }
        }

        if ($changed === 0) {
            throw new \InvalidArgumentException('Цены не изменятся: результат совпадает с текущими.');
        }

        $lines = array(
            'Тип цены: #' . $priceTypeId,
            'Изменится товаров: ' . $changed . ' из ' . count($targets),
            '',
        );
        $lines = array_merge($lines, $sample);
        if ($changed > count($sample)) {
            $lines[] = '…и ещё ' . ($changed - count($sample));
        }

        return array(
            'summary' => 'Цены: ' . $changed . ' товаров (' . self::modeLabel($mode, $value) . ')',
            'payload' => array(
                'args' => array(
                    'mode' => $mode,
                    'value' => $value,
                    'round_to' => $round,
                    'before' => $before,
                ),
            ),
            'preview' => implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $before = (array)($args['before'] ?? array());

        if ($before === array()) {
            throw new \InvalidArgumentException('Нет данных для изменения цен.');
        }

        $connection = Application::getConnection();
        $table = PriceTable::getTableName();
        $mode = (string)$args['mode'];
        $value = (float)$args['value'];
        $round = (float)$args['round_to'];
        $updated = 0;

        $connection->startTransaction();

        try {
            foreach ($before as $priceId => $oldPrice) {
                $new = self::calculate((float)$oldPrice, $mode, $value, $round);

                $connection->queryExecute(
                    'UPDATE ' . $table . ' SET PRICE = ' . number_format($new, 4, '.', '')
                    . " WHERE ID = " . (int)$priceId
                );
                $updated++;
            }

            $connection->commitTransaction();
        } catch (\Throwable $e) {
            $connection->rollbackTransaction();

            throw new \RuntimeException('Не удалось изменить цены: ' . mb_substr($e->getMessage(), 0, 200));
        }

        return array('updated' => $updated);
    }

    public static function rollback(array $payload): void
    {
        $before = (array)($payload['args']['before'] ?? array());
        if ($before === array()) {
            return;
        }

        $connection = Application::getConnection();
        $table = PriceTable::getTableName();

        $connection->startTransaction();

        try {
            foreach ($before as $priceId => $oldPrice) {
                $connection->queryExecute(
                    'UPDATE ' . $table . ' SET PRICE = ' . number_format((float)$oldPrice, 4, '.', '')
                    . ' WHERE ID = ' . (int)$priceId
                );
            }

            $connection->commitTransaction();
        } catch (\Throwable $e) {
            $connection->rollbackTransaction();

            throw new \RuntimeException('Не удалось вернуть цены: ' . mb_substr($e->getMessage(), 0, 200));
        }
    }

    private static function calculate(float $price, string $mode, float $value, float $round): float
    {
        $result = match ($mode) {
            'percent' => $price * (1 + $value / 100),
            'delta' => $price + $value,
            default => $value,
        };

        if ($result < 0) {
            $result = 0.0;
        }

        if ($round > 0) {
            $result = round($result / $round) * $round;
        }

        return round($result, 2);
    }

    private static function mode(array $args): string
    {
        $mode = strtolower(trim((string)($args['mode'] ?? 'percent')));

        if (!in_array($mode, array('percent', 'delta', 'set'), true)) {
            throw new \InvalidArgumentException('Режим: percent, delta или set.');
        }

        return $mode;
    }

    private static function modeLabel(string $mode, float $value): string
    {
        return match ($mode) {
            'percent' => ($value > 0 ? '+' : '') . $value . '%',
            'delta' => ($value > 0 ? '+' : '') . $value . ' руб.',
            default => 'по ' . $value . ' руб.',
        };
    }

    private static function priceTypeId(array $args): int
    {
        $priceTypeId = (int)($args['price_type_id'] ?? 0);

        if ($priceTypeId > 0) {
            return $priceTypeId;
        }

        $row = \Bitrix\Catalog\GroupTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=BASE' => 'Y'),
            'order' => array('SORT' => 'ASC'),
            'limit' => 1,
        ))->fetch();

        if (!$row) {
            $row = \Bitrix\Catalog\GroupTable::getList(array(
                'select' => array('ID'),
                'order' => array('SORT' => 'ASC'),
                'limit' => 1,
            ))->fetch();
        }

        return (int)($row['ID'] ?? 0);
    }

    /**
     * @return array<int,array{ID:int,PRODUCT_ID:int,PRICE:float}>
     */
    private static function targets(array $args, int $priceTypeId): array
    {
        $filter = array('=CATALOG_GROUP_ID' => $priceTypeId);

        $elementIds = array_values(array_filter(array_map('intval', (array)($args['element_ids'] ?? array()))));

        if ($elementIds !== array()) {
            $filter['=PRODUCT_ID'] = $elementIds;
        } else {
            $productFilter = array();

            if ((int)($args['iblock_id'] ?? 0) > 0) {
                $productFilter['=IBLOCK_ID'] = (int)$args['iblock_id'];
            }

            if ((int)($args['section_id'] ?? 0) > 0) {
                // Элемент может лежать в нескольких разделах — берём связку, а не основное поле.
                $sectionElementIds = array();
                foreach (SectionElementTable::getList(array(
                    'select' => array('ELEMENT_ID'),
                    'filter' => array('=SECTION_ID' => self::sectionIds((int)$args['section_id'])),
                    'limit' => self::MAX_ITEMS + 1,
                ))->fetchAll() as $row) {
                    $sectionElementIds[] = (int)$row['ELEMENT_ID'];
                }

                if ($sectionElementIds === array()) {
                    return array();
                }

                $productFilter['=ID'] = array_values(array_unique($sectionElementIds));
            }

            if ($productFilter !== array()) {
                $ids = array();
                foreach (ElementTable::getList(array(
                    'select' => array('ID'),
                    'filter' => $productFilter,
                    'limit' => self::MAX_ITEMS + 1,
                ))->fetchAll() as $row) {
                    $ids[] = (int)$row['ID'];
                }

                if ($ids === array()) {
                    return array();
                }

                $filter['=PRODUCT_ID'] = $ids;
            }
        }

        $rows = PriceTable::getList(array(
            'select' => array('ID', 'PRODUCT_ID', 'PRICE'),
            'filter' => $filter,
            'order' => array('ID' => 'ASC'),
            'limit' => self::MAX_ITEMS + 1,
        ))->fetchAll();

        return array_map(static fn (array $row): array => array(
            'ID' => (int)$row['ID'],
            'PRODUCT_ID' => (int)$row['PRODUCT_ID'],
            'PRICE' => (float)$row['PRICE'],
        ), $rows);
    }

    /** Раздел и все его подразделы. */
    private static function sectionIds(int $sectionId): array
    {
        $ids = array($sectionId);

        // Подразделы любой глубины: иначе часть каталога выпадает из выборки.
        $queue = array($sectionId);
        $seen = array($sectionId => true);

        while ($queue !== array() && count($ids) < 5000) {
            $current = array_shift($queue);

            $children = \Bitrix\Iblock\SectionTable::getList(array(
                'select' => array('ID'),
                'filter' => array('=IBLOCK_SECTION_ID' => $current),
                'limit' => 2000,
            ))->fetchAll();

            foreach ($children as $child) {
                $childId = (int)$child['ID'];
                if ($childId > 0 && !isset($seen[$childId])) {
                    $seen[$childId] = true;
                    $ids[] = $childId;
                    $queue[] = $childId;
                }
            }
        }

        return $ids;
    }
}
