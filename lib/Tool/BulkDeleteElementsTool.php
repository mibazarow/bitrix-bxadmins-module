<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Loader;

/**
 * Массовое удаление элементов инфоблока. Необратимо точечно: откат идёт
 * через точку отката (SQL-дамп + git), поэтому требует confirm_destroy.
 */
class BulkDeleteElementsTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    private const MAX_ITEMS = 20000;

    public static function getName(): string
    {
        return 'bulk_delete_elements';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'bulk_delete_elements',
            'description' => 'Массово удаляет элементы инфоблока по разделу (с подразделами) или списку ID, до 20 000. Необратимо точечно: откат — через точку отката. Требует confirm_destroy=true.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'iblock_id' => array('type' => 'integer', 'description' => 'ID инфоблока (обязателен)'),
                    'section_id' => array('type' => 'integer', 'description' => 'Удалить элементы раздела'),
                    'include_subsections' => array('type' => 'boolean', 'description' => 'Включая подразделы, по умолчанию да'),
                    'element_ids' => array(
                        'type' => 'array',
                        'items' => array('type' => 'integer'),
                        'description' => 'Конкретные ID элементов (альтернатива section_id)',
                    ),
                    'confirm_destroy' => array('type' => 'boolean', 'description' => 'Подтверждение необратимого удаления: передай true'),
                ),
                'required' => array('iblock_id', 'confirm_destroy'),
            ),
        );
    }

    public static function needsSqlDump(): bool
    {
        return true;
    }

    public static function rollbackNeedsRestore(): bool
    {
        return true;
    }

    public static function preview(array $args): array
    {
        if (empty($args['confirm_destroy'])) {
            throw new \InvalidArgumentException('Массовое удаление требует confirm_destroy=true.');
        }

        $iblockId = (int)($args['iblock_id'] ?? 0);
        $iblock = IblockElementFields::loadIblock($iblockId);
        $ids = self::targetIds($args, $iblockId);

        if ($ids === array()) {
            throw new \InvalidArgumentException('Под отбор не попал ни один элемент.');
        }

        if (count($ids) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException(
                'Элементов ' . count($ids) . ' — больше предела ' . self::MAX_ITEMS . '. Сузьте отбор.'
            );
        }

        $names = ElementTable::getList(array(
            'select' => array('ID', 'NAME'),
            'filter' => array('=ID' => array_slice($ids, 0, 20)),
            'limit' => 20,
        ))->fetchAll();

        $sample = array();
        foreach ($names as $row) {
            $sample[] = '#' . $row['ID'] . ' ' . $row['NAME'];
        }

        return array(
            'summary' => 'Удалить ' . count($ids) . ' элем. из «' . $iblock['name'] . '»',
            'payload' => array(
                'args' => array('iblock_id' => $iblockId, 'ids' => $ids),
                'before' => array('count' => count($ids)),
            ),
            'preview' => 'Будет удалено элементов: ' . count($ids) . ' (инфоблок «' . $iblock['name'] . '»).'
                . "\n" . implode("\n", $sample)
                . (count($ids) > 20 ? "\n… и ещё " . (count($ids) - 20) . '.' : '')
                . "\nОткат возможен только через точку отката (restore_site).",
        );
    }

    public static function apply(array $payload): array
    {
        $ids = (array)($payload['args']['ids'] ?? array());

        if ($ids === array()) {
            throw new \InvalidArgumentException('Нет элементов для удаления.');
        }

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен.');
        }

        $deleted = 0;
        $failed = array();

        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0) {
                continue;
            }

            // Легаси-исключение: удаление элемента с чисткой связей — только CIBlockElement.
            if (\CIBlockElement::Delete($id)) {
                $deleted++;
            } else {
                $failed[] = $id;
            }
        }

        return array(
            'deleted' => $deleted,
            'failed' => array_slice($failed, 0, 50),
            'failed_count' => count($failed),
        );
    }

    public static function rollback(array $payload): void
    {
        // Откат выполняет ChangeService через точку отката (rollbackNeedsRestore).
    }

    /** @return int[] */
    private static function targetIds(array $args, int $iblockId): array
    {
        $elementIds = isset($args['element_ids']) && is_array($args['element_ids'])
            ? array_values(array_unique(array_filter(array_map('intval', $args['element_ids']), static function (int $id): bool {
                return $id > 0;
            })))
            : array();

        if ($elementIds !== array()) {
            $rows = ElementTable::getList(array(
                'select' => array('ID'),
                'filter' => array('=IBLOCK_ID' => $iblockId, '=ID' => array_slice($elementIds, 0, self::MAX_ITEMS + 1)),
                'limit' => self::MAX_ITEMS + 1,
            ))->fetchAll();

            return array_map(static function (array $row): int {
                return (int)$row['ID'];
            }, $rows);
        }

        $sectionId = (int)($args['section_id'] ?? 0);
        if ($sectionId <= 0) {
            throw new \InvalidArgumentException('Нужен section_id или element_ids.');
        }

        $section = SectionTable::getList(array(
            'select' => array('ID', 'IBLOCK_ID', 'NAME'),
            'filter' => array('=ID' => $sectionId),
            'limit' => 1,
        ))->fetch();

        if (!$section || (int)$section['IBLOCK_ID'] !== $iblockId) {
            throw new \InvalidArgumentException('Раздел #' . $sectionId . ' не найден в инфоблоке #' . $iblockId . '.');
        }

        $rows = \CIBlockElement::GetList(
            array('ID' => 'ASC'),
            array(
                'IBLOCK_ID' => $iblockId,
                'SECTION_ID' => $sectionId,
                'INCLUDE_SUBSECTIONS' => !empty($args['include_subsections']) ? 'Y' : 'N',
            ),
            false,
            array('nTopCount' => self::MAX_ITEMS + 1),
            array('ID')
        );

        $ids = array();
        while ($row = $rows->Fetch()) {
            $ids[] = (int)$row['ID'];
        }

        return $ids;
    }
}
