<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Loader;

/** Изменение раздела инфоблока. */
class UpdateIblockSectionTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'update_iblock_section';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_iblock_section',
            'description' => 'Меняет название, код, активность или сортировку раздела инфоблока.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'section_id' => array('type' => 'integer', 'description' => 'ID раздела'),
                    'name' => array('type' => 'string', 'description' => 'Новое название'),
                    'code' => array('type' => 'string', 'description' => 'Новый символьный код'),
                    'active' => array('type' => 'boolean', 'description' => 'Активен'),
                    'sort' => array('type' => 'integer', 'description' => 'Сортировка'),
                ),
                'required' => array('section_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $sectionId = (int)($args['section_id'] ?? 0);
        if ($sectionId <= 0) {
            throw new \InvalidArgumentException('Параметр section_id обязателен.');
        }

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен.');
        }

        $section = SectionTable::getById($sectionId)->fetch();
        if (!$section) {
            throw new \InvalidArgumentException('Раздел #' . $sectionId . ' не найден.');
        }

        $before = array(
            'NAME' => (string)$section['NAME'],
            'CODE' => (string)($section['CODE'] ?? ''),
            'ACTIVE' => (string)$section['ACTIVE'],
            'SORT' => (int)$section['SORT'],
        );

        $fields = array();
        if (array_key_exists('name', $args)) {
            $fields['NAME'] = IblockElementFields::name($args['name']);
        }
        if (array_key_exists('code', $args)) {
            $fields['CODE'] = IblockElementFields::code($args['code']);
        }
        if (array_key_exists('active', $args)) {
            $fields['ACTIVE'] = IblockElementFields::active($args['active']);
        }
        if (array_key_exists('sort', $args)) {
            $fields['SORT'] = IblockElementFields::sort($args['sort']);
        }

        if ($fields === array()) {
            throw new \InvalidArgumentException('Не передано ни одного поля для изменения.');
        }

        $lines = array();
        foreach ($fields as $field => $value) {
            $lines[] = $field . ': ' . var_export($before[$field] ?? null, true) . ' → ' . var_export($value, true);
        }

        return array(
            'summary' => 'Изменение раздела #' . $sectionId . ' «' . $before['NAME'] . '»',
            'payload' => array(
                'args' => array('section_id' => $sectionId, 'fields' => $fields),
                'before' => $before,
            ),
            'preview' => implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $sectionId = (int)($payload['args']['section_id'] ?? 0);
        $fields = (array)($payload['args']['fields'] ?? array());

        // Легаси-исключение: обновление раздела с пересчётом дерева — только CIBlockSection.
        $section = new \CIBlockSection();
        if (!$section->Update($sectionId, $fields)) {
            throw new \RuntimeException(IblockElementFields::legacyError($section, 'Не удалось обновить раздел.'));
        }

        return array('section_id' => $sectionId, 'updated' => array_keys($fields));
    }

    public static function rollback(array $payload): void
    {
        $sectionId = (int)($payload['args']['section_id'] ?? 0);
        $before = (array)($payload['before'] ?? array());

        if ($sectionId <= 0 || $before === array()) {
            return;
        }

        $section = new \CIBlockSection();
        if (!$section->Update($sectionId, $before)) {
            throw new \RuntimeException(IblockElementFields::legacyError($section, 'Не удалось вернуть прежние значения раздела.'));
        }
    }
}
