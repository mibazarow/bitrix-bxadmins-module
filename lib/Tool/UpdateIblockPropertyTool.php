<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\PropertyEnumerationTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Loader;

/** Правка свойства инфоблока и значений списка. */
class UpdateIblockPropertyTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'update_iblock_property';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_iblock_property',
            'description' => 'Меняет свойство инфоблока: название, активность, обязательность, множественность, значения списка. Свойства — list_iblock_properties.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'property_id' => array('type' => 'integer', 'description' => 'ID свойства'),
                    'name' => array('type' => 'string', 'description' => 'Новое название'),
                    'active' => array('type' => 'boolean', 'description' => 'Активность'),
                    'required' => array('type' => 'boolean', 'description' => 'Обязательное'),
                    'multiple' => array('type' => 'boolean', 'description' => 'Множественное'),
                    'sort' => array('type' => 'integer', 'description' => 'Сортировка'),
                    'default_value' => array('type' => 'string', 'description' => 'Значение по умолчанию'),
                    'hint' => array('type' => 'string', 'description' => 'Подсказка'),
                    'enum_values' => array(
                        'type' => 'array',
                        'items' => array('type' => 'string'),
                        'description' => 'Новый список значений (только для свойства типа «Список»)',
                    ),
                ),
                'required' => array('property_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $propertyId = (int)($args['property_id'] ?? 0);
        if ($propertyId <= 0) {
            throw new \InvalidArgumentException('Параметр property_id обязателен.');
        }

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен.');
        }

        $property = PropertyTable::getList(array(
            'select' => array('ID', 'IBLOCK_ID', 'CODE', 'NAME', 'ACTIVE', 'SORT', 'IS_REQUIRED', 'MULTIPLE', 'PROPERTY_TYPE', 'DEFAULT_VALUE', 'HINT'),
            'filter' => array('=ID' => $propertyId),
            'limit' => 1,
        ))->fetch();

        if (!$property) {
            throw new \InvalidArgumentException('Свойство #' . $propertyId . ' не найдено.');
        }

        $fields = array();
        $lines = array();

        $map = array(
            'name' => array('NAME', 'string'),
            'active' => array('ACTIVE', 'flag'),
            'required' => array('IS_REQUIRED', 'flag'),
            'multiple' => array('MULTIPLE', 'flag'),
            'sort' => array('SORT', 'int'),
            'default_value' => array('DEFAULT_VALUE', 'string'),
            'hint' => array('HINT', 'string'),
        );

        foreach ($map as $argName => $meta) {
            if (!array_key_exists($argName, $args)) {
                continue;
            }

            list($field, $kind) = $meta;
            $value = $args[$argName];

            if ($kind === 'flag') {
                $value = ((bool)$value) ? 'Y' : 'N';
            } elseif ($kind === 'int') {
                $value = (int)$value;
            } else {
                $value = trim((string)$value);
            }

            if ((string)($property[$field] ?? '') === (string)$value) {
                continue;
            }

            $fields[$field] = $value;
            $lines[] = $field . ': ' . (string)($property[$field] ?? '') . ' → ' . (string)$value;
        }

        $enumValues = null;
        if (array_key_exists('enum_values', $args)) {
            if ((string)$property['PROPERTY_TYPE'] !== 'L') {
                throw new \InvalidArgumentException('Значения списка можно менять только у свойства типа «Список».');
            }

            $enumValues = array_values(array_filter(array_map(
                static fn ($value): string => trim((string)$value),
                (array)$args['enum_values']
            ), static fn (string $value): bool => $value !== ''));

            if ($enumValues === array()) {
                throw new \InvalidArgumentException('Список значений пуст.');
            }

            $current = array();
            foreach (PropertyEnumerationTable::getList(array(
                'select' => array('VALUE'),
                'filter' => array('=PROPERTY_ID' => $propertyId),
                'order' => array('SORT' => 'ASC', 'ID' => 'ASC'),
            ))->fetchAll() as $row) {
                $current[] = (string)$row['VALUE'];
            }

            if ($current === $enumValues) {
                $enumValues = null;
            } else {
                $lines[] = 'Значения: ' . implode(', ', $current) . ' → ' . implode(', ', $enumValues);
            }
        }

        if ($fields === array() && $enumValues === null) {
            throw new \InvalidArgumentException('Изменений нет: переданные значения совпадают с текущими.');
        }

        return array(
            'summary' => 'Свойство ' . $property['CODE'] . ' инфоблока #' . $property['IBLOCK_ID'],
            'payload' => array(
                'args' => array(
                    'property_id' => $propertyId,
                    'fields' => $fields,
                    'enum_values' => $enumValues,
                ),
                'before' => array(
                    'raw' => \Bitrix\Main\Application::getConnection()->query(
                        'SELECT * FROM b_iblock_property WHERE ID = ' . $propertyId
                    )->fetch() ?: array(),
                    'enum' => PropertyEnumerationTable::getList(array(
                        'select' => array('ID', 'VALUE', 'SORT', 'DEF'),
                        'filter' => array('=PROPERTY_ID' => $propertyId),
                        'order' => array('SORT' => 'ASC', 'ID' => 'ASC'),
                    ))->fetchAll(),
                ),
            ),
            'preview' => 'Свойство «' . $property['NAME'] . '» (' . $property['CODE'] . ")\n" . implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $propertyId = (int)($args['property_id'] ?? 0);
        $fields = (array)($args['fields'] ?? array());

        if ($fields !== array()) {
            $property = new \CIBlockProperty();
            if (!$property->Update($propertyId, $fields)) {
                throw new \RuntimeException(strip_tags((string)$property->LAST_ERROR) ?: 'Не удалось изменить свойство.');
            }
        }

        $enumValues = $args['enum_values'] ?? null;
        if (is_array($enumValues)) {
            foreach (PropertyEnumerationTable::getList(array(
                'select' => array('ID'),
                'filter' => array('=PROPERTY_ID' => $propertyId),
            ))->fetchAll() as $row) {
                // Составной первичный ключ (ID, PROPERTY_ID) — удаляем легаси-методом.
                \CIBlockPropertyEnum::Delete((int)$row['ID']);
            }

            $sort = 100;
            foreach ($enumValues as $value) {
                PropertyEnumerationTable::add(array(
                    'PROPERTY_ID' => $propertyId,
                    'VALUE' => (string)$value,
                    // Уникальный индекс (PROPERTY_ID, XML_ID): пустой XML_ID у второго
                    // значения даёт дубликат.
                    'XML_ID' => 'bx' . substr(md5($propertyId . '|' . $value . '|' . $sort), 0, 12),
                    'SORT' => $sort,
                    'DEF' => 'N',
                ));
                $sort += 100;
            }
        }

        return array('property_id' => $propertyId, 'updated' => array_keys($fields));
    }

    public static function rollback(array $payload): void
    {
        $propertyId = (int)($payload['args']['property_id'] ?? 0);
        $before = (array)($payload['before'] ?? array());

        if ($propertyId <= 0) {
            return;
        }

        $raw = (array)($before['raw'] ?? array());
        if ($raw !== array()) {
            $fields = $raw;
            unset($fields['ID']);
            $property = new \CIBlockProperty();
            $property->Update($propertyId, $fields);
        }

        $enum = (array)($before['enum'] ?? array());
        if ($enum !== array()) {
            foreach (PropertyEnumerationTable::getList(array(
                'select' => array('ID'),
                'filter' => array('=PROPERTY_ID' => $propertyId),
            ))->fetchAll() as $row) {
                // Составной первичный ключ (ID, PROPERTY_ID) — удаляем легаси-методом.
                \CIBlockPropertyEnum::Delete((int)$row['ID']);
            }

            $sort = 100;
            foreach ($enum as $row) {
                PropertyEnumerationTable::add(array(
                    'PROPERTY_ID' => $propertyId,
                    'VALUE' => (string)$row['VALUE'],
                    'XML_ID' => 'bxr' . substr(md5($propertyId . '|' . $row['VALUE'] . '|' . $sort), 0, 12),
                    'SORT' => (int)$row['SORT'],
                    'DEF' => (string)($row['DEF'] ?? 'N'),
                ));
                $sort += 100;
            }
        }
    }
}
