<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Loader;

/** Изменение торгового предложения: поля и свойства. */
class UpdateOfferTool extends AbstractChangeTool
{
    public const DOMAIN = 'commerce';

    public static function getName(): string
    {
        return 'update_offer';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_offer',
            'description' => 'Меняет поля и свойства торгового предложения. Свойства — объектом {КОД: значение}.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'offer_id' => array('type' => 'integer', 'description' => 'ID предложения'),
                    'name' => array('type' => 'string', 'description' => 'Новое название'),
                    'active' => array('type' => 'boolean', 'description' => 'Активность'),
                    'sort' => array('type' => 'integer', 'description' => 'Сортировка'),
                    'properties' => array(
                        'type' => 'object',
                        'description' => 'Свойства: {"COLOR": "Синий"}',
                    ),
                ),
                'required' => array('offer_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $offerId = (int)($args['offer_id'] ?? 0);
        if ($offerId <= 0) {
            throw new \InvalidArgumentException('Параметр offer_id обязателен.');
        }

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен.');
        }

        $offer = ElementTable::getById($offerId)->fetch();
        if (!$offer) {
            throw new \InvalidArgumentException('Предложение #' . $offerId . ' не найдено.');
        }

        $fields = array();
        if (array_key_exists('name', $args)) {
            $fields['NAME'] = IblockElementFields::name($args['name']);
        }
        if (array_key_exists('active', $args)) {
            $fields['ACTIVE'] = IblockElementFields::active($args['active']);
        }
        if (array_key_exists('sort', $args)) {
            $fields['SORT'] = IblockElementFields::sort($args['sort']);
        }

        $propertyMap = IblockElementFields::propertyMap((int)$offer['IBLOCK_ID']);
        $values = array();
        $lines = array();

        foreach ((array)($args['properties'] ?? array()) as $code => $value) {
            $code = strtoupper(trim((string)$code));
            if (!isset($propertyMap[$code])) {
                throw new \InvalidArgumentException('У торговых предложений нет свойства ' . $code . '.');
            }

            $values[$propertyMap[$code]] = (string)$value;
            $lines[] = $code . ': ' . (string)$value;
        }

        if ($fields === array() && $values === array()) {
            throw new \InvalidArgumentException('Не передано ни одного поля для изменения.');
        }

        $before = array(
            'NAME' => (string)$offer['NAME'],
            'ACTIVE' => (string)$offer['ACTIVE'],
            'SORT' => (int)$offer['SORT'],
        );

        return array(
            'summary' => 'Изменение предложения #' . $offerId . ' «' . $before['NAME'] . '»',
            'payload' => array(
                'args' => array(
                    'offer_id' => $offerId,
                    'iblock_id' => (int)$offer['IBLOCK_ID'],
                    'fields' => $fields,
                    'values' => $values,
                ),
                'before' => $before,
            ),
            'preview' => implode("\n", array_merge(
                array_map(
                    static fn(string $field, $value): string => $field . ': ' . var_export($before[$field] ?? null, true) . ' → ' . var_export($value, true),
                    array_keys($fields),
                    array_values($fields)
                ),
                $lines
            )),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $offerId = (int)($args['offer_id'] ?? 0);

        $fields = (array)($args['fields'] ?? array());

        $properties = array();
        foreach ((array)($args['values'] ?? array()) as $propertyId => $value) {
            $properties[(int)$propertyId] = array('VALUE' => $value);
        }

        if ($properties !== array()) {
            $fields['PROPERTY_VALUES'] = $properties;
        }

        $element = new \CIBlockElement();
        if (!$element->Update($offerId, $fields)) {
            throw new \RuntimeException(IblockElementFields::legacyError($element, 'Не удалось обновить предложение.'));
        }

        return array('offer_id' => $offerId, 'updated' => array_keys($fields));
    }

    public static function rollback(array $payload): void
    {
        $offerId = (int)($payload['args']['offer_id'] ?? 0);
        $before = (array)($payload['before'] ?? array());

        if ($offerId <= 0 || $before === array()) {
            return;
        }

        $element = new \CIBlockElement();
        if (!$element->Update($offerId, $before)) {
            throw new \RuntimeException(IblockElementFields::legacyError($element, 'Не удалось вернуть прежние значения предложения.'));
        }
    }
}
