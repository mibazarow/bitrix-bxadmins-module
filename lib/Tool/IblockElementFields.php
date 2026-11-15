<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\IblockTable;
use Bitrix\Main\Loader;

/** Разбор и валидация полей элемента инфоблока — общая часть create/update. */
final class IblockElementFields
{
    private const MAX_PREVIEW_CHARS = 15000;
    private const MAX_DETAIL_CHARS = 200000;
    private const PREVIEW_VALUE_CHARS = 300;

    /** @return array{id:int,name:string,code:string} */
    public static function loadIblock(int $iblockId): array
    {
        if ($iblockId <= 0) {
            throw new \InvalidArgumentException('Параметр iblock_id обязателен и должен быть положительным числом.');
        }

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен на этом сайте.');
        }

        $iblock = IblockTable::getById($iblockId)->fetch();
        if (!$iblock) {
            throw new \InvalidArgumentException('Инфоблок #' . $iblockId . ' не найден.');
        }

        return array(
            'id' => (int)$iblock['ID'],
            'name' => (string)$iblock['NAME'],
            'code' => (string)($iblock['CODE'] ?? ''),
        );
    }

    public static function name($value): string
    {
        $name = trim((string)$value);

        if ($name === '') {
            throw new \InvalidArgumentException('Название элемента обязательно и не может быть пустым.');
        }

        if (mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('Название элемента слишком длинное (максимум 255 символов).');
        }

        return $name;
    }

    public static function code($value): string
    {
        $code = trim((string)$value);

        if (mb_strlen($code) > 255) {
            throw new \InvalidArgumentException('Символьный код слишком длинный (максимум 255 символов).');
        }

        return $code;
    }

    public static function active($value): string
    {
        if (is_bool($value)) {
            return $value ? 'Y' : 'N';
        }

        $normalized = strtoupper(trim((string)$value));

        if (in_array($normalized, array('Y', '1', 'TRUE', 'ON', 'YES'), true)) {
            return 'Y';
        }

        if (in_array($normalized, array('N', '0', 'FALSE', 'OFF', 'NO', ''), true)) {
            return 'N';
        }

        throw new \InvalidArgumentException('Поле active должно быть Y или N.');
    }

    public static function sort($value): int
    {
        $raw = trim((string)$value);

        if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
            throw new \InvalidArgumentException('Поле sort должно быть целым числом.');
        }

        return (int)$raw;
    }

    public static function type($value): string
    {
        $type = strtolower(trim((string)$value));

        if ($type === '') {
            return 'text';
        }

        if (!in_array($type, array('text', 'html'), true)) {
            throw new \InvalidArgumentException('Тип текста должен быть text или html.');
        }

        return $type;
    }

    public static function previewText($value): string
    {
        return self::text($value, 'preview_text', self::MAX_PREVIEW_CHARS);
    }

    public static function detailText($value): string
    {
        return self::text($value, 'detail_text', self::MAX_DETAIL_CHARS);
    }

    /** @param array<int,array{0:string,1:string}> $rows */
    public static function formatPreview(array $rows): string
    {
        $lines = array();

        foreach ($rows as $row) {
            $lines[] = $row[0] . ': ' . self::shorten($row[1]);
        }

        return implode("\n", $lines);
    }

    /**
     * Карта свойств инфоблока: КОД => ID (коды в верхнем регистре).
     *
     * @return array<string,int>
     */
    public static function propertyMap(int $iblockId): array
    {
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return array();
        }

        $map = array();

        foreach (\Bitrix\Iblock\PropertyTable::getList(array(
            'select' => array('ID', 'CODE'),
            'filter' => array('=IBLOCK_ID' => $iblockId),
        ))->fetchAll() as $row) {
            $code = strtoupper((string)($row['CODE'] ?? ''));
            if ($code !== '') {
                $map[$code] = (int)$row['ID'];
            }
        }

        return $map;
    }

    /**
     * Ошибка легаси-объекта без HTML — общая для create/update.
     * Принимает любой объект с LAST_ERROR (элемент, раздел, инфоблок, тип).
     */
    public static function legacyError(object $element, string $fallback): string
    {
        return \Mibazarow\Bxadmins\Service\LegacyError::message($element, $fallback);
    }

    /**
     * Готовит значения свойств для CIBlockElement::Add/Update и SetPropertyValuesEx.
     * Ключи — коды свойств, значения — строка или массив строк.
     *
     * @return array{values:array<int,string|string[]>,lines:string[]}
     */
    public static function prepareProperties(int $iblockId, array $properties): array
    {
        if ($properties === array()) {
            return array('values' => array(), 'lines' => array());
        }

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен на этом сайте.');
        }

        $map = array();
        foreach (\Bitrix\Iblock\PropertyTable::getList(array(
            'select' => array('ID', 'CODE', 'MULTIPLE', 'PROPERTY_TYPE', 'LIST_TYPE'),
            'filter' => array('=IBLOCK_ID' => $iblockId),
        ))->fetchAll() as $row) {
            $code = strtoupper((string)($row['CODE'] ?? ''));
            if ($code !== '') {
                $map[$code] = $row;
            }
        }

        $values = array();
        $lines = array();

        foreach ($properties as $code => $value) {
            $code = strtoupper(trim((string)$code));

            if (!isset($map[$code])) {
                throw new \InvalidArgumentException(
                    'У инфоблока нет свойства ' . $code . '. Доступны: ' . implode(', ', array_slice(array_keys($map), 0, 30))
                );
            }

            $property = $map[$code];
            $prepared = self::preparePropertyValue($property, $value);

            $values[(int)$property['ID']] = $prepared;
            $lines[] = $code . ' = ' . (is_array($prepared) ? implode(', ', $prepared) : (string)$prepared);
        }

        return array('values' => $values, 'lines' => $lines);
    }

    /**
     * Текущие значения свойств в формате, пригодном для SetPropertyValuesEx и отката.
     *
     * @param int[] $propertyIds
     * @return array<int,array>
     */
    public static function readPropertyValues(int $elementId, int $iblockId, array $propertyIds): array
    {
        $values = array();

        foreach ($propertyIds as $propertyId) {
            $rows = \CIBlockElement::GetProperty($iblockId, $elementId, array('sort' => 'asc'), array('ID' => (int)$propertyId));

            while ($row = $rows->Fetch()) {
                if (!isset($values[$propertyId])) {
                    $values[$propertyId] = array();
                }

                $values[$propertyId][] = array(
                    'VALUE' => $row['VALUE'],
                    'DESCRIPTION' => (string)($row['DESCRIPTION'] ?? ''),
                );
            }
        }

        return $values;
    }

    /** @return string|string[] */
    private static function preparePropertyValue(array $property, $value)
    {
        $isMultiple = (string)$property['MULTIPLE'] === 'Y';
        $items = $isMultiple && is_array($value) ? $value : array($value);

        $prepared = array();

        foreach ($items as $item) {
            $item = is_scalar($item) ? (string)$item : '';

            if ((string)$property['PROPERTY_TYPE'] === 'L' && $item !== '' && !ctype_digit($item)) {
                $enum = \Bitrix\Iblock\PropertyEnumerationTable::getList(array(
                    'select' => array('ID'),
                    'filter' => array('=PROPERTY_ID' => (int)$property['ID'], '=VALUE' => $item),
                    'limit' => 1,
                ))->fetch();

                if (!$enum) {
                    throw new \InvalidArgumentException('В списке нет значения «' . $item . '».');
                }

                $item = (string)$enum['ID'];
            }

            $prepared[] = $item;
        }

        return $isMultiple ? $prepared : ($prepared[0] ?? '');
    }

    private static function text($value, string $field, int $maxChars): string
    {
        $text = (string)$value;

        if (mb_strlen($text) > $maxChars) {
            throw new \InvalidArgumentException('Поле ' . $field . ' слишком длинное (максимум ' . $maxChars . ' символов).');
        }

        return $text;
    }

    private static function shorten(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '—';
        }

        return mb_strlen($value) > self::PREVIEW_VALUE_CHARS
            ? mb_substr($value, 0, self::PREVIEW_VALUE_CHARS) . '…'
            : $value;
    }
}
