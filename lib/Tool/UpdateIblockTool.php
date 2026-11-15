<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\IblockTable;
use Bitrix\Main\Loader;

/** Изменение и удаление инфоблока. */
class UpdateIblockTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'update_iblock';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_iblock',
            'description' => 'Меняет название, код, активность или сортировку инфоблока. Удаление — отдельным инструментом delete_iblock.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'iblock_id' => array('type' => 'integer', 'description' => 'ID инфоблока'),
                    'name' => array('type' => 'string', 'description' => 'Новое название'),
                    'code' => array('type' => 'string', 'description' => 'Новый символьный код'),
                    'active' => array('type' => 'boolean', 'description' => 'Активность'),
                    'sort' => array('type' => 'integer', 'description' => 'Сортировка'),
                    'index_elements' => array('type' => 'boolean', 'description' => 'Индексировать элементы'),
                ),
                'required' => array('iblock_id'),
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
            throw new \RuntimeException('Модуль iblock недоступен.');
        }

        $iblock = IblockTable::getById($iblockId)->fetch();
        if (!$iblock) {
            throw new \InvalidArgumentException('Инфоблок #' . $iblockId . ' не найден.');
        }

        $before = array(
            'NAME' => (string)$iblock['NAME'],
            'CODE' => (string)($iblock['CODE'] ?? ''),
            'ACTIVE' => (string)$iblock['ACTIVE'],
            'SORT' => (int)$iblock['SORT'],
        );

        $fields = array();
        if (array_key_exists('name', $args)) {
            $fields['NAME'] = IblockElementFields::name($args['name']);
        }
        if (array_key_exists('code', $args)) {
            $code = IblockElementFields::code($args['code']);
            if ($code !== '' && !preg_match('/^[a-z0-9_-]+$/', $code)) {
                throw new \InvalidArgumentException('Код инфоблока: только строчная латиница, цифры, дефис и подчёркивание.');
            }
            $fields['CODE'] = $code;
        }
        if (array_key_exists('active', $args)) {
            $fields['ACTIVE'] = IblockElementFields::active($args['active']);
        }
        if (array_key_exists('sort', $args)) {
            $fields['SORT'] = IblockElementFields::sort($args['sort']);
        }
        if (array_key_exists('index_elements', $args)) {
            $fields['INDEX_ELEMENT'] = ($args['index_elements'] ?? true) !== false ? 'Y' : 'N';
        }

        if ($fields === array()) {
            throw new \InvalidArgumentException('Не передано ни одного поля для изменения.');
        }

        $lines = array();
        foreach ($fields as $field => $value) {
            $lines[] = $field . ': ' . var_export($before[$field] ?? null, true) . ' → ' . var_export($value, true);
        }

        return array(
            'summary' => 'Изменение инфоблока #' . $iblockId . ' «' . $before['NAME'] . '»',
            'payload' => array(
                'args' => array('iblock_id' => $iblockId, 'fields' => $fields),
                'before' => $before,
            ),
            'preview' => implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $iblockId = (int)($payload['args']['iblock_id'] ?? 0);
        $fields = (array)($payload['args']['fields'] ?? array());

        $iblock = new \CIBlock();
        if (!$iblock->Update($iblockId, $fields)) {
            throw new \RuntimeException(IblockElementFields::legacyError($iblock, 'Не удалось обновить инфоблок.'));
        }

        return array('iblock_id' => $iblockId, 'updated' => array_keys($fields));
    }

    public static function rollback(array $payload): void
    {
        $iblockId = (int)($payload['args']['iblock_id'] ?? 0);
        $before = (array)($payload['before'] ?? array());

        if ($iblockId <= 0 || $before === array()) {
            return;
        }

        $iblock = new \CIBlock();
        if (!$iblock->Update($iblockId, $before)) {
            throw new \RuntimeException(IblockElementFields::legacyError($iblock, 'Не удалось вернуть прежние значения инфоблока.'));
        }
    }
}
