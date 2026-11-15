<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Loader;

/** Удаление раздела инфоблока. */
class DeleteIblockSectionTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'delete_iblock_section';
    }

    public static function rollbackNeedsRestore(): bool
    {
        // Раздел удаляется вместе с элементами, которые были только в нём: точечно восстановить нельзя.
        return true;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_iblock_section',
            'description' => 'Удаляет раздел инфоблока. Показывает, сколько вложенных разделов и элементов будет затронуто.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'section_id' => array('type' => 'integer', 'description' => 'ID раздела'),
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

        $left = (int)$section['LEFT_MARGIN'];
        $right = (int)$section['RIGHT_MARGIN'];
        $iblockId = (int)$section['IBLOCK_ID'];

        $children = SectionTable::getCount(array(
            '=IBLOCK_ID' => $iblockId,
            '>LEFT_MARGIN' => $left,
            '<RIGHT_MARGIN' => $right,
        ));

        $elements = ElementTable::getCount(array('=IBLOCK_SECTION_ID' => $sectionId));

        return array(
            'summary' => 'Удаление раздела «' . $section['NAME'] . '»',
            'payload' => array(
                'args' => array('section_id' => $sectionId),
                'before' => array(
                    'name' => (string)$section['NAME'],
                    'iblock_id' => $iblockId,
                    'children' => $children,
                    'elements' => $elements,
                ),
            ),
            'preview' => 'Будет удалён раздел «' . $section['NAME'] . '» (ID ' . $sectionId . ').'
                . ' Вложенных разделов: ' . $children . ', элементов в нём: ' . $elements . '.'
                . ' Перед удалением создаётся SQL-дамп.',
        );
    }

    public static function apply(array $payload): array
    {
        $sectionId = (int)($payload['args']['section_id'] ?? 0);

        // Легаси-исключение: удаление раздела с пересчётом дерева — только CIBlockSection.
        $section = new \CIBlockSection();
        if (!$section->Delete($sectionId)) {
            throw new \RuntimeException(IblockElementFields::legacyError($section, 'Не удалось удалить раздел.'));
        }

        return array('section_id' => $sectionId, 'deleted' => true);
    }

    public static function rollback(array $payload): void
    {
        // Раздел с деревом и элементами вручную не восстановить: используйте
        // restore_site для изменения, которое удалило раздел.
        throw new \RuntimeException(
            'Удаление раздела нельзя отменить по одному изменению. Верните весь сайт к состоянию перед этим изменением — restore_site.'
        );
    }
}
