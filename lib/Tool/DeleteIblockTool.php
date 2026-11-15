<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Loader;

/**
 * Удаление инфоблока. Необратимая операция: требует снапшота БД (он делается
 * автоматически) и явного подтверждения пользователя.
 */
class DeleteIblockTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'delete_iblock';
    }

    public static function rollbackNeedsRestore(): bool
    {
        // Инфоблок удаляется вместе с элементами, разделами и свойствами: точечно восстановить нельзя.
        return true;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_iblock',
            'description' => 'Удаляет инфоблок вместе с элементами и разделами. Необратимо: перед удалением делается SQL-дамп.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'iblock_id' => array('type' => 'integer', 'description' => 'ID инфоблока'),
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

        $elements = ElementTable::getCount(array('=IBLOCK_ID' => $iblockId));
        $sections = SectionTable::getCount(array('=IBLOCK_ID' => $iblockId));

        return array(
            'summary' => 'Удаление инфоблока #' . $iblockId . ' «' . $iblock['NAME'] . '»',
            'payload' => array(
                'args' => array('iblock_id' => $iblockId),
                'before' => array(
                    'name' => (string)$iblock['NAME'],
                    'code' => (string)($iblock['CODE'] ?? ''),
                    'elements' => $elements,
                    'sections' => $sections,
                ),
            ),
            'preview' => 'Будет удалён инфоблок «' . $iblock['NAME'] . '» (код ' . ($iblock['CODE'] ?? '') . '): '
                . $elements . ' элементов, ' . $sections . ' разделов. Действие необратимо, дамп БД будет создан.',
        );
    }

    public static function apply(array $payload): array
    {
        $iblockId = (int)($payload['args']['iblock_id'] ?? 0);

        $iblock = new \CIBlock();
        if (!$iblock->Delete($iblockId)) {
            throw new \RuntimeException(IblockElementFields::legacyError($iblock, 'Не удалось удалить инфоблок.'));
        }

        return array('iblock_id' => $iblockId, 'deleted' => true);
    }

    public static function rollback(array $payload): void
    {
        // Восстановление — только из SQL-дампа, который создаётся перед удалением.
        throw new \RuntimeException('Удаление инфоблока откатывается восстановлением SQL-дампа, созданного перед операцией.');
    }
}
