<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\ProductTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Loader;

/**
 * Импорт элементов инфоблока из CSV.
 *
 * Разделитель определяется по первой строке (запятая или точка с запятой).
 * Колонки сопоставляются по коду свойства или по служебным именам
 * NAME / CODE / SECTION_CODE, а для товаров — PRICE / PRICE_TYPE_ID / CURRENCY /
 * QUANTITY. Существующие элементы обновляются по CODE.
 */
class ImportCsvElementsTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    private const MAX_ROWS = 500;

    public static function getName(): string
    {
        return 'import_csv_elements';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'import_csv_elements',
            'description' => 'Импортирует элементы инфоблока из CSV: первая строка — заголовки (NAME, CODE, SECTION_CODE, коды свойств; для товаров — PRICE, PRICE_TYPE_ID, CURRENCY, QUANTITY). До 500 строк. Существующие элементы обновляются по CODE.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'iblock_id' => array('type' => 'integer', 'description' => 'ID инфоблока'),
                    'csv' => array('type' => 'string', 'description' => 'Содержимое CSV целиком'),
                    'delimiter' => array('type' => 'string', 'description' => 'Разделитель: , или ; (определяется автоматически)'),
                    'update_existing' => array('type' => 'boolean', 'description' => 'Обновлять найденные по CODE, по умолчанию да'),
                ),
                'required' => array('iblock_id', 'csv'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $iblockId = (int)($args['iblock_id'] ?? 0);
        if ($iblockId <= 0) {
            throw new \InvalidArgumentException('Параметр iblock_id обязателен.');
        }

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock не установлен.');
        }

        $parsed = self::parse((string)($args['csv'] ?? ''), (string)($args['delimiter'] ?? ''));

        if ($parsed['rows'] === array()) {
            throw new \InvalidArgumentException('В CSV нет строк с данными.');
        }

        if (count($parsed['rows']) > self::MAX_ROWS) {
            throw new \InvalidArgumentException(
                'Строк ' . count($parsed['rows']) . ' — больше предела ' . self::MAX_ROWS . '. Разделите файл.'
            );
        }

        $header = $parsed['header'];
        if (!in_array('NAME', $header, true)) {
            throw new \InvalidArgumentException('В заголовке нужна колонка NAME.');
        }

        $unknown = self::unknownColumns($iblockId, $header);

        $lines = array(
            'Колонки: ' . implode(', ', $header),
            'Строк: ' . count($parsed['rows']),
        );

        if ($unknown !== array()) {
            $lines[] = 'Нераспознанные колонки будут пропущены: ' . implode(', ', $unknown);
        }

        $previewRows = array_slice($parsed['rows'], 0, 5);
        foreach ($previewRows as $row) {
            $lines[] = '· ' . ($row['NAME'] ?? '(без названия)');
        }

        return array(
            'summary' => 'Импорт ' . count($parsed['rows']) . ' элементов в инфоблок #' . $iblockId,
            'payload' => array(
                'args' => array(
                    'iblock_id' => $iblockId,
                    'header' => $header,
                    'rows' => $parsed['rows'],
                    'update_existing' => ($args['update_existing'] ?? true) !== false,
                ),
            ),
            'preview' => implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $iblockId = (int)$args['iblock_id'];
        $header = (array)$args['header'];
        $updateExisting = !empty($args['update_existing']);

        $map = self::propertyMap($iblockId);
        $created = 0;
        $updated = 0;
        $prices = 0;
        $stocks = 0;
        $failed = array();

        foreach ((array)$args['rows'] as $index => $row) {
            $name = trim((string)($row['NAME'] ?? ''));
            if ($name === '') {
                continue;
            }

            $fields = array('NAME' => $name, 'IBLOCK_ID' => $iblockId);
            $properties = array();

            foreach ($header as $column) {
                if (!array_key_exists($column, $row)) {
                    continue;
                }

                $value = trim((string)$row[$column]);

                switch ($column) {
                    case 'NAME':
                        break;
                    case 'CODE':
                        if ($value !== '') {
                            $fields['CODE'] = $value;
                        }
                        break;
                    case 'PREVIEW_TEXT':
                        $fields['PREVIEW_TEXT'] = $value;
                        break;
                    case 'DETAIL_TEXT':
                        $fields['DETAIL_TEXT'] = $value;
                        break;
                    case 'ACTIVE':
                        $fields['ACTIVE'] = in_array(mb_strtolower($value), array('n', '0', 'нет', 'no'), true) ? 'N' : 'Y';
                        break;
                    case 'SECTION_CODE':
                        if ($value !== '') {
                            $sectionId = self::sectionId($iblockId, $value);
                            if ($sectionId > 0) {
                                $fields['IBLOCK_SECTION_ID'] = $sectionId;
                            }
                        }
                        break;
                    default:
                        if (isset($map[$column])) {
                            $properties[$column] = $value;
                        }
                }
            }

            if ($properties !== array()) {
                $fields['PROPERTY_VALUES'] = $properties;
            }

            $code = (string)($fields['CODE'] ?? '');
            $existing = $code !== '' && $updateExisting ? self::findByCode($iblockId, $code) : null;

            try {
                if ($existing !== null) {
                    $element = new \CIBlockElement();
                    if (!$element->Update($existing, $fields)) {
                        $failed[] = $name . ': ' . strip_tags((string)$element->LAST_ERROR);
                        continue;
                    }
                    $elementId = $existing;
                    $updated++;
                } else {
                    $element = new \CIBlockElement();
                    $elementId = (int)$element->Add($fields);
                    if ($elementId <= 0) {
                        $failed[] = $name . ': ' . strip_tags((string)$element->LAST_ERROR);
                        continue;
                    }
                    $created++;
                }

                $catalog = self::applyCatalog($elementId, $row);
                $prices += $catalog['price'] ? 1 : 0;
                $stocks += $catalog['stock'] ? 1 : 0;
            } catch (\Throwable $e) {
                $failed[] = $name . ': ' . mb_substr($e->getMessage(), 0, 120);
            }
        }

        return array(
            'iblock_id' => $iblockId,
            'created' => $created,
            'updated' => $updated,
            'prices' => $prices,
            'stocks' => $stocks,
            'failed' => array_slice($failed, 0, 20),
        );
    }

    public static function rollback(array $payload): void
    {
        // Импорт меняет много объектов: точечно не откатить, только полный откат сайта.
        throw new \RuntimeException(
            'Импорт нельзя отменить по одному изменению. Верните весь сайт к состоянию перед импортом — restore_site.'
        );
    }

    public static function rollbackNeedsRestore(): bool
    {
        return true;
    }

    /**
     * Цена и остаток для строки импорта. Колонки необязательные: без них
     * инструмент остаётся обычным импортом элементов.
     *
     * @return array{price:bool,stock:bool}
     */
    private static function applyCatalog(int $elementId, array $row): array
    {
        $price = isset($row['PRICE']) ? (float)str_replace(array(' ', ','), array('', '.'), (string)$row['PRICE']) : 0.0;
        $quantity = isset($row['QUANTITY']) ? (float)str_replace(array(' ', ','), array('', '.'), (string)$row['QUANTITY']) : null;

        if (($price <= 0 && $quantity === null) || !Loader::includeModule('catalog')) {
            return array('price' => false, 'stock' => false);
        }

        $priceTypeId = max(1, (int)($row['PRICE_TYPE_ID'] ?? 1));
        $currency = strtoupper(trim((string)($row['CURRENCY'] ?? 'RUB'))) ?: 'RUB';

        if ($quantity !== null) {
            $product = ProductTable::getById($elementId)->fetch();
            if ($product) {
                ProductTable::update($elementId, array('QUANTITY' => $quantity));
            } else {
                ProductTable::add(array(
                    'ID' => $elementId,
                    'QUANTITY' => $quantity,
                    'QUANTITY_TRACE' => 'Y',
                    'CAN_BUY_ZERO' => 'N',
                ));
            }
        }

        if ($price > 0) {
            $existing = PriceTable::getList(array(
                'select' => array('ID'),
                'filter' => array('=PRODUCT_ID' => $elementId, '=CATALOG_GROUP_ID' => $priceTypeId),
                'limit' => 1,
            ))->fetch();

            $fields = array(
                'PRODUCT_ID' => $elementId,
                'CATALOG_GROUP_ID' => $priceTypeId,
                'PRICE' => $price,
                'PRICE_SCALE' => $price,
                'CURRENCY' => $currency,
            );

            if ($existing) {
                PriceTable::update((int)$existing['ID'], $fields);
            } else {
                PriceTable::add($fields);
            }
        }

        return array('price' => $price > 0, 'stock' => $quantity !== null);
    }

    /** @return array{header:string[],rows:array<int,array<string,string>>} */
    private static function parse(string $csv, string $delimiter): array
    {
        $csv = str_replace(array("\r\n", "\r"), "\n", trim($csv));

        if ($csv === '') {
            return array('header' => array(), 'rows' => array());
        }

        // Разделитель — ровно один символ. Многосимвольный (или пустой) не ломает
        // разбор: определяем по первой строке сами.
        $delimiter = $delimiter === '\\t' ? "\t" : $delimiter;
        if ($delimiter === '' || mb_strlen($delimiter) !== 1) {
            $firstLine = strtok($csv, "\n") ?: '';
            $candidates = array(';' => 0, ',' => 0, "\t" => 0, '|' => 0);
            foreach ($candidates as $candidate => $_) {
                $candidates[$candidate] = substr_count($firstLine, $candidate);
            }
            arsort($candidates);
            $delimiter = (string)array_key_first($candidates);
        }

        $lines = explode("\n", $csv);
        $header = str_getcsv((string)array_shift($lines), $delimiter);
        $header = array_map(static fn ($h): string => strtoupper(trim((string)$h)), $header);

        $rows = array();
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line, $delimiter);
            $row = array();

            foreach ($header as $index => $column) {
                $row[$column] = (string)($values[$index] ?? '');
            }

            $rows[] = $row;
        }

        return array('header' => $header, 'rows' => $rows);
    }

    /** @return string[] колонки, которых нет ни среди полей, ни среди свойств */
    private static function unknownColumns(int $iblockId, array $header): array
    {
        $known = array(
            'NAME', 'CODE', 'PREVIEW_TEXT', 'DETAIL_TEXT', 'ACTIVE', 'SECTION_CODE',
            'PRICE', 'PRICE_TYPE_ID', 'CURRENCY', 'QUANTITY',
        );
        $map = self::propertyMap($iblockId);

        $unknown = array();
        foreach ($header as $column) {
            if (!in_array($column, $known, true) && !isset($map[$column])) {
                $unknown[] = $column;
            }
        }

        return $unknown;
    }

    /** @return array<string,true> коды свойств инфоблока */
    private static function propertyMap(int $iblockId): array
    {
        $map = array();

        foreach (PropertyTable::getList(array(
            'select' => array('CODE'),
            'filter' => array('=IBLOCK_ID' => $iblockId, '!CODE' => false),
            'limit' => 200,
        ))->fetchAll() as $row) {
            $map[strtoupper((string)$row['CODE'])] = true;
        }

        return $map;
    }

    private static function findByCode(int $iblockId, string $code): ?int
    {
        $row = ElementTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=IBLOCK_ID' => $iblockId, '=CODE' => $code),
            'limit' => 1,
        ))->fetch();

        return $row ? (int)$row['ID'] : null;
    }

    private static function sectionId(int $iblockId, string $code): int
    {
        $row = \Bitrix\Iblock\SectionTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=IBLOCK_ID' => $iblockId, '=CODE' => $code),
            'limit' => 1,
        ))->fetch();

        return $row ? (int)$row['ID'] : 0;
    }
}
