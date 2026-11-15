<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Loader;

/** Удаление элемента инфоблока с возможностью отката. */
class DeleteIblockElementTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'delete_iblock_element';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_iblock_element',
            'description' => 'Удаляет элемент инфоблока. Перед удалением сохраняется снимок полей, поэтому откат возможен.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'element_id' => array('type' => 'integer', 'description' => 'ID элемента'),
                ),
                'required' => array('element_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $elementId = (int)($args['element_id'] ?? 0);
        if ($elementId <= 0) {
            throw new \InvalidArgumentException('Параметр element_id обязателен.');
        }

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен.');
        }

        $element = ElementTable::getById($elementId)->fetch();
        if (!$element) {
            throw new \InvalidArgumentException('Элемент #' . $elementId . ' не найден.');
        }

        $before = array(
            'IBLOCK_ID' => (int)$element['IBLOCK_ID'],
            'IBLOCK_SECTION_ID' => (int)($element['IBLOCK_SECTION_ID'] ?? 0),
            'NAME' => (string)$element['NAME'],
            'CODE' => (string)($element['CODE'] ?? ''),
            'ACTIVE' => (string)$element['ACTIVE'],
            'SORT' => (int)$element['SORT'],
            'PREVIEW_TEXT' => (string)($element['PREVIEW_TEXT'] ?? ''),
            'DETAIL_TEXT' => (string)($element['DETAIL_TEXT'] ?? ''),
        );

        return array(
            'summary' => 'Удаление элемента #' . $elementId . ' «' . $before['NAME'] . '»',
            'payload' => array('args' => array('element_id' => $elementId), 'before' => $before),
            'preview' => 'Будет удалён элемент «' . $before['NAME'] . '» (инфоблок #' . $before['IBLOCK_ID'] . ').'
                . ' Снимок полей сохранён — откат восстановит элемент.',
        );
    }

    public static function apply(array $payload): array
    {
        $elementId = (int)($payload['args']['element_id'] ?? 0);

        // Легаси-исключение: удаление элемента с обработкой связанных данных — только CIBlockElement.
        $element = new \CIBlockElement();
        if (!$element->Delete($elementId)) {
            throw new \RuntimeException(IblockElementFields::legacyError($element, 'Не удалось удалить элемент.'));
        }

        return array('element_id' => $elementId, 'deleted' => true);
    }

    public static function rollback(array $payload): void
    {
        $before = (array)($payload['before'] ?? array());
        if ($before === array()) {
            return;
        }

        $element = new \CIBlockElement();
        $newId = $element->Add($before);

        if (!$newId) {
            throw new \RuntimeException(IblockElementFields::legacyError($element, 'Не удалось восстановить элемент при откате.'));
        }
    }
}
