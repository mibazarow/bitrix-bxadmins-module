<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Loader;

/** Новый highload-блок с набором пользовательских полей. */
class CreateHighloadBlockTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    /** Типы полей, которые разрешено создавать. */
    private const FIELD_TYPES = array('string', 'integer', 'double', 'boolean', 'date', 'datetime', 'text', 'enumeration', 'file');

    public static function getName(): string
    {
        return 'create_highload_block';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_highload_block',
            'description' => 'Создаёт highload-блок (справочник) с полями. Поля: name (латиницей), label (подпись), type (string|integer|double|boolean|date|datetime|text|enumeration|file).',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'name' => array('type' => 'string', 'description' => 'Название блока, латиницей: PhoneModels'),
                    'table_name' => array('type' => 'string', 'description' => 'Имя таблицы, по умолчанию из названия'),
                    'fields' => array(
                        'type' => 'array',
                        'items' => array(
                            'type' => 'object',
                            'properties' => array(
                                'name' => array('type' => 'string'),
                                'label' => array('type' => 'string'),
                                'type' => array('type' => 'string'),
                                'multiple' => array('type' => 'boolean'),
                                'required' => array('type' => 'boolean'),
                            ),
                            'required' => array('name'),
                        ),
                    ),
                ),
                'required' => array('name'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $name = trim((string)($args['name'] ?? ''));
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException('Название блока — латиница, цифры и подчёркивание, начиная с буквы.');
        }

        if (!Loader::includeModule('highloadblock')) {
            throw new \RuntimeException('Модуль highloadblock недоступен.');
        }

        $tableName = trim((string)($args['table_name'] ?? ''));
        if ($tableName === '') {
            $tableName = 'bx_' . mb_strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
        }

        if (preg_match('/^[a-z][a-z0-9_]{2,60}$/', $tableName) !== 1) {
            throw new \InvalidArgumentException('Имя таблицы: строчная латиница, цифры, подчёркивание.');
        }

        if (HighloadBlockTable::getList(array('filter' => array('=NAME' => $name), 'limit' => 1))->fetch()) {
            throw new \InvalidArgumentException('Блок с названием ' . $name . ' уже есть.');
        }

        if (HighloadBlockTable::getList(array('filter' => array('=TABLE_NAME' => $tableName), 'limit' => 1))->fetch()) {
            throw new \InvalidArgumentException('Таблица ' . $tableName . ' уже занята.');
        }

        $fields = self::normalizeFields((array)($args['fields'] ?? array()));

        $lines = array('Блок ' . $name . ' (таблица ' . $tableName . ')');
        foreach ($fields as $field) {
            $lines[] = '· ' . $field['FIELD_NAME'] . ' — ' . $field['USER_TYPE_ID'] . ' (' . $field['EDIT_FORM_LABEL'] . ')';
        }

        return array(
            'summary' => 'Highload-блок ' . $name,
            'payload' => array('args' => array('name' => $name, 'table_name' => $tableName, 'fields' => $fields)),
            'preview' => implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());

        $added = HighloadBlockTable::add(array(
            'NAME' => (string)$args['name'],
            'TABLE_NAME' => (string)$args['table_name'],
        ));

        if (!$added->isSuccess()) {
            throw new \RuntimeException(implode('; ', $added->getErrorMessages()));
        }

        $blockId = (int)$added->getId();
        $createdFields = array();

        foreach ((array)($args['fields'] ?? array()) as $field) {
            $userFieldId = (int)(new \CUserTypeEntity())->Add(array(
                'ENTITY_ID' => 'HLBLOCK_' . $blockId,
                'FIELD_NAME' => (string)$field['FIELD_NAME'],
                'USER_TYPE_ID' => (string)$field['USER_TYPE_ID'],
                'XML_ID' => '',
                'SORT' => (int)($field['SORT'] ?? 500),
                'MULTIPLE' => !empty($field['MULTIPLE']) ? 'Y' : 'N',
                'MANDATORY' => !empty($field['MANDATORY']) ? 'Y' : 'N',
                'SHOW_FILTER' => 'I',
                'SHOW_IN_LIST' => 'Y',
                'EDIT_IN_LIST' => 'Y',
                'IS_SEARCHABLE' => 'N',
                'EDIT_FORM_LABEL' => array('ru' => (string)$field['EDIT_FORM_LABEL']),
                'LIST_COLUMN_LABEL' => array('ru' => (string)$field['EDIT_FORM_LABEL']),
                'LIST_FILTER_LABEL' => array('ru' => (string)$field['EDIT_FORM_LABEL']),
            ));

            if ($userFieldId > 0) {
                $createdFields[] = $userFieldId;
            }
        }

        return array(
            'block_id' => $blockId,
            'name' => (string)$args['name'],
            'table_name' => (string)$args['table_name'],
            'field_ids' => $createdFields,
        );
    }

    public static function rollback(array $payload): void
    {
        $blockId = (int)($payload['result']['block_id'] ?? 0);
        if ($blockId <= 0) {
            return;
        }

        // Удаление блока снимает таблицу и пользовательские поля.
        HighloadBlockTable::delete($blockId);
    }

    /** @return array<int,array> */
    private static function normalizeFields(array $fields): array
    {
        $normalized = array();
        $sort = 100;

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $fieldName = 'UF_' . strtoupper(trim((string)($field['name'] ?? '')));
            if (preg_match('/^UF_[A-Z0-9_]{1,40}$/', $fieldName) !== 1) {
                throw new \InvalidArgumentException('Имя поля должно быть латиницей: ' . (string)($field['name'] ?? ''));
            }

            $type = strtolower(trim((string)($field['type'] ?? 'string')));
            if (!in_array($type, self::FIELD_TYPES, true)) {
                throw new \InvalidArgumentException('Неизвестный тип поля: ' . $type);
            }

            $normalized[] = array(
                'FIELD_NAME' => $fieldName,
                'USER_TYPE_ID' => $type,
                'EDIT_FORM_LABEL' => trim((string)($field['label'] ?? $field['name'] ?? $fieldName)),
                'MULTIPLE' => !empty($field['multiple']),
                'MANDATORY' => !empty($field['required']),
                'SORT' => $sort,
            );

            $sort += 100;
        }

        return $normalized;
    }
}
