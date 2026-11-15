<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Loader;

/** Добавление свойства в инфоблок. */
class CreateIblockPropertyTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    private const TYPES = array(
        'string' => 'S',
        'number' => 'N',
        'list' => 'L',
        'file' => 'F',
        'element' => 'E',
        'section' => 'G',
        'html' => 'S',
        'date' => 'S',
    );

    public static function getName(): string
    {
        return 'create_iblock_property';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_iblock_property',
            'description' => 'Добавляет свойство в инфоблок. Тип: string, number, list, file, element, section, html, date.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'iblock_id' => array('type' => 'integer', 'description' => 'ID инфоблока'),
                    'code' => array('type' => 'string', 'description' => 'Символьный код, заглавными: COUNTRY'),
                    'name' => array('type' => 'string', 'description' => 'Название свойства'),
                    'type' => array('type' => 'string', 'description' => 'Тип: string (по умолчанию), number, list, file, element, section, html, date'),
                    'multiple' => array('type' => 'boolean', 'description' => 'Множественное'),
                    'required' => array('type' => 'boolean', 'description' => 'Обязательное'),
                    'values' => array(
                        'type' => 'array',
                        'items' => array('type' => 'string'),
                        'description' => 'Значения для типа list',
                    ),
                    'link_iblock_id' => array('type' => 'integer', 'description' => 'Инфоблок-источник для типов element/section'),
                    'sort' => array('type' => 'integer', 'description' => 'Сортировка'),
                ),
                'required' => array('iblock_id', 'code', 'name'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $iblockId = (int)($args['iblock_id'] ?? 0);
        $code = strtoupper(trim((string)($args['code'] ?? '')));
        $name = IblockElementFields::name($args['name'] ?? null);
        $type = strtolower(trim((string)($args['type'] ?? 'string'))) ?: 'string';

        if ($iblockId <= 0) {
            throw new \InvalidArgumentException('Параметр iblock_id обязателен.');
        }

        if ($code === '' || !preg_match('/^[A-Z][A-Z0-9_]*$/', $code)) {
            throw new \InvalidArgumentException('Код свойства: заглавная латиница, цифры и подчёркивание, например COUNTRY.');
        }

        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('Неизвестный тип: ' . $type . '. Доступны: ' . implode(', ', array_keys(self::TYPES)));
        }

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен.');
        }

        $iblock = \Bitrix\Iblock\IblockTable::getById($iblockId)->fetch();
        if (!$iblock) {
            throw new \InvalidArgumentException('Инфоблок #' . $iblockId . ' не найден.');
        }

        $existing = PropertyTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=IBLOCK_ID' => $iblockId, '=CODE' => $code),
            'limit' => 1,
        ))->fetch();

        if ($existing) {
            throw new \InvalidArgumentException('У инфоблока уже есть свойство ' . $code . ' (ID ' . $existing['ID'] . ').');
        }

        $values = is_array($args['values'] ?? null) ? array_values(array_map('strval', $args['values'])) : array();

        $payload = array(
            'iblock_id' => $iblockId,
            'code' => $code,
            'name' => $name,
            'type' => self::TYPES[$type],
            'multiple' => ($args['multiple'] ?? false) === true ? 'Y' : 'N',
            'required' => ($args['required'] ?? false) === true ? 'Y' : 'N',
            'values' => $values,
            'link_iblock_id' => (int)($args['link_iblock_id'] ?? 0),
            'sort' => IblockElementFields::sort($args['sort'] ?? 500),
        );

        return array(
            'summary' => 'Свойство ' . $code . ' в инфоблоке «' . $iblock['NAME'] . '»',
            'payload' => array('args' => $payload),
            'preview' => 'Новое свойство ' . $code . ' («' . $name . '», тип ' . $type
                . (($args['multiple'] ?? false) === true ? ', множественное' : '')
                . (($args['required'] ?? false) === true ? ', обязательное' : '')
                . ($values !== array() ? ', значения: ' . implode(', ', $values) : '') . ').',
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());

        $fields = array(
            'IBLOCK_ID' => (int)$args['iblock_id'],
            'CODE' => (string)$args['code'],
            'NAME' => (string)$args['name'],
            'PROPERTY_TYPE' => (string)$args['type'],
            'MULTIPLE' => (string)$args['multiple'],
            'IS_REQUIRED' => (string)$args['required'],
            'SORT' => (int)$args['sort'],
        );

        if ((int)$args['link_iblock_id'] > 0) {
            $fields['LINK_IBLOCK_ID'] = (int)$args['link_iblock_id'];
        }

        $values = (array)($args['values'] ?? array());
        if ($values !== array()) {
            $fields['LIST_TYPE'] = 'L';
            $fields['VALUES'] = array();
            foreach ($values as $index => $value) {
                $fields['VALUES'][] = array(
                    'VALUE' => (string)$value,
                    'SORT' => ($index + 1) * 10,
                    'DEF' => 'N',
                );
            }
        }

        // Легаси-исключение: добавление свойства вместе со списком значений — только CIBlockProperty.
        $property = new \CIBlockProperty();
        $id = $property->Add($fields);

        if (!$id) {
            throw new \RuntimeException(IblockElementFields::legacyError($property, 'Не удалось создать свойство.'));
        }

        return array('property_id' => (int)$id, 'code' => (string)$args['code']);
    }

    public static function rollback(array $payload): void
    {
        $propertyId = (int)($payload['result']['property_id'] ?? 0);
        if ($propertyId <= 0) {
            return;
        }

        $property = new \CIBlockProperty();
        $property->Delete($propertyId);
    }
}
