<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Iblock\SectionElementTable;
use Bitrix\Main\Loader;

/**
 * Массовая правка элементов: активность, раздел, значения свойств.
 *
 * Отбор — инфоблок, раздел (с подразделами), список ID или все элементы.
 * Снимок изменяемых значений сохраняется, откат возвращает их по одному.
 */
class BulkUpdateElementsTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    private const MAX_ITEMS = 20000;

    public static function getName(): string
    {
        return 'bulk_update_elements';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'bulk_update_elements',
            'description' => 'Массово меняет элементы: включить/выключить, перенести в раздел, проставить свойства. Отбор — инфоблок, раздел (с подразделами) или список ID. До 20 000 элементов: одно подтверждение и один откат на всю выборку.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'iblock_id' => array('type' => 'integer', 'description' => 'ID инфоблока (обязателен)'),
                    'section_id' => array('type' => 'integer', 'description' => 'Ограничить разделом с подразделами'),
                    'element_ids' => array(
                        'type' => 'array',
                        'items' => array('type' => 'integer'),
                        'description' => 'Конкретные элементы',
                    ),
                    'active' => array('type' => 'boolean', 'description' => 'Включить или выключить'),
                    'move_to_section' => array('type' => 'integer', 'description' => 'Перенести в раздел (ID)'),
                    'properties' => array(
                        'type' => 'object',
                        'description' => 'Проставить свойства по коду: {"BRAND": "Nike"}',
                    ),
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
            throw new \RuntimeException('Модуль iblock не установлен.');
        }

        $hasChange = array_key_exists('active', $args)
            || (int)($args['move_to_section'] ?? 0) > 0
            || (is_array($args['properties'] ?? null) && $args['properties'] !== array());

        if (!$hasChange) {
            throw new \InvalidArgumentException('Не указано, что менять: active, move_to_section или properties.');
        }

        $ids = self::targetIds($args, $iblockId);

        if ($ids === array()) {
            throw new \InvalidArgumentException('Под фильтр не попал ни один элемент.');
        }

        if (count($ids) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException(
                'Элементов ' . count($ids) . ' — больше предела ' . self::MAX_ITEMS . '. Сузьте отбор.'
            );
        }

        $properties = is_array($args['properties'] ?? null) ? $args['properties'] : array();
        if ($properties !== array()) {
            $map = self::propertyCodes($iblockId);
            foreach (array_keys($properties) as $code) {
                if (!isset($map[strtoupper((string)$code)])) {
                    throw new \InvalidArgumentException('У инфоблока нет свойства ' . $code . '.');
                }
            }
        }

        $before = self::snapshot($ids, $iblockId, array_map('strval', array_keys($properties)));

        $lines = array(
            'Элементов: ' . count($ids),
            'Инфоблок: #' . $iblockId,
        );

        if (array_key_exists('active', $args)) {
            $lines[] = 'Активность → ' . (!empty($args['active']) ? 'включены' : 'выключены');
        }

        if ((int)($args['move_to_section'] ?? 0) > 0) {
            $lines[] = 'Перенос в раздел #' . (int)$args['move_to_section'];
        }

        foreach ($properties as $code => $value) {
            $lines[] = 'Свойство ' . $code . ' → ' . mb_substr((string)$value, 0, 60);
        }

        $sample = array_slice(array_column($before, 'NAME'), 0, 5);
        $lines[] = 'Например: ' . implode(', ', $sample);

        return array(
            'summary' => 'Массовая правка ' . count($ids) . ' элементов',
            'payload' => array(
                'args' => array(
                    'iblock_id' => $iblockId,
                    'ids' => $ids,
                    'active' => array_key_exists('active', $args) ? (bool)$args['active'] : null,
                    'move_to_section' => (int)($args['move_to_section'] ?? 0),
                    'properties' => $properties,
                ),
                'before' => array('elements' => $before),
            ),
            'preview' => implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $ids = (array)($args['ids'] ?? array());

        $changed = 0;

        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0) {
                continue;
            }

            $fields = array();

            if ($args['active'] !== null) {
                $fields['ACTIVE'] = $args['active'] ? 'Y' : 'N';
            }

            if ((int)$args['move_to_section'] > 0) {
                $fields['IBLOCK_SECTION_ID'] = (int)$args['move_to_section'];
            }

            if ($fields !== array()) {
                $element = new \CIBlockElement();
                if ($element->Update($id, $fields)) {
                    $changed++;
                }
            }

            if (!empty($args['properties'])) {
                $element = new \CIBlockElement();
                $element->SetPropertyValuesEx($id, (int)$args['iblock_id'], (array)$args['properties']);
                $changed++;
            }
        }

        return array('updated' => $changed, 'total' => count($ids));
    }

    public static function rollback(array $payload): void
    {
        $before = (array)($payload['before']['elements'] ?? array());
        $iblockId = (int)($payload['args']['iblock_id'] ?? 0);

        foreach ($before as $row) {
            $id = (int)$row['ID'];

            $fields = array();
            if (array_key_exists('ACTIVE', $row)) {
                $fields['ACTIVE'] = (string)$row['ACTIVE'];
            }
            if (array_key_exists('IBLOCK_SECTION_ID', $row)) {
                $fields['IBLOCK_SECTION_ID'] = (int)$row['IBLOCK_SECTION_ID'] ?: false;
            }

            if ($fields !== array()) {
                (new \CIBlockElement())->Update($id, $fields);
            }

            if (!empty($row['PROPERTIES'])) {
                (new \CIBlockElement())->SetPropertyValuesEx($id, $iblockId, (array)$row['PROPERTIES']);
            }
        }
    }

    /** @return int[] */
    private static function targetIds(array $args, int $iblockId): array
    {
        $explicit = array_values(array_filter(array_map('intval', (array)($args['element_ids'] ?? array()))));

        if ($explicit !== array()) {
            return array_slice($explicit, 0, self::MAX_ITEMS + 1);
        }

        $filter = array('=IBLOCK_ID' => $iblockId);

        if ((int)($args['section_id'] ?? 0) > 0) {
            $sectionIds = self::sectionIds((int)$args['section_id']);
            $ids = array();

            foreach (SectionElementTable::getList(array(
                'select' => array('ELEMENT_ID'),
                'filter' => array('=SECTION_ID' => $sectionIds),
                'limit' => self::MAX_ITEMS + 1,
            ))->fetchAll() as $row) {
                $ids[] = (int)$row['ELEMENT_ID'];
            }

            if ($ids === array()) {
                return array();
            }

            $filter['=ID'] = array_values(array_unique($ids));
        }

        $ids = array();
        foreach (ElementTable::getList(array(
            'select' => array('ID'),
            'filter' => $filter,
            'limit' => self::MAX_ITEMS + 1,
        ))->fetchAll() as $row) {
            $ids[] = (int)$row['ID'];
        }

        return $ids;
    }

    /**
     * Снимок изменяемых полей. Свойства читаем заранее: без этого откат
     * вернёт активность и раздел, но не значения свойств.
     *
     * @param int[] $ids
     * @param string[] $propertyCodes коды свойств, которые будут изменены
     * @return array<int,array>
     */
    private static function snapshot(array $ids, int $iblockId, array $propertyCodes): array
    {
        $rows = ElementTable::getList(array(
            'select' => array('ID', 'NAME', 'ACTIVE', 'IBLOCK_SECTION_ID'),
            'filter' => array('=ID' => $ids),
            'limit' => self::MAX_ITEMS + 1,
        ))->fetchAll();

        $snapshot = array();

        foreach ($rows as $row) {
            $elementId = (int)$row['ID'];
            $properties = array();

            foreach ($propertyCodes as $code) {
                $properties[$code] = self::propertyValues($elementId, $iblockId, (string)$code);
            }

            $snapshot[] = array(
                'ID' => $elementId,
                'NAME' => (string)$row['NAME'],
                'ACTIVE' => (string)$row['ACTIVE'],
                'IBLOCK_SECTION_ID' => (int)($row['IBLOCK_SECTION_ID'] ?? 0),
                'PROPERTIES' => $properties,
            );
        }

        return $snapshot;
    }

    /** Текущее значение свойства в виде, пригодном для SetPropertyValuesEx. */
    private static function propertyValues(int $elementId, int $iblockId, string $code): array
    {
        $values = array();

        // Легаси-исключение: чтение значений свойства — только CIBlockElement::GetProperty.
        $rows = \CIBlockElement::GetProperty($iblockId, $elementId, array('sort' => 'asc'), array('CODE' => $code));

        while ($row = $rows->Fetch()) {
            $values[] = array(
                'VALUE' => $row['VALUE'],
                'DESCRIPTION' => (string)($row['DESCRIPTION'] ?? ''),
            );
        }

        return $values;
    }

    /** @return array<string,true> */
    private static function propertyCodes(int $iblockId): array
    {
        $map = array();

        foreach (PropertyTable::getList(array(
            'select' => array('CODE'),
            'filter' => array('=IBLOCK_ID' => $iblockId, '!CODE' => false),
            'limit' => 200,
        ))->fetchAll() as $row) {
            $map[strtoupper((string)$row['CODE'])] = true;
        }

        return $map;
    }

    /** @return int[] раздел и подразделы */
    private static function sectionIds(int $sectionId): array
    {
        $ids = array($sectionId);

        $queue = array($sectionId);
        $seen = array($sectionId => true);

        while ($queue !== array() && count($ids) < 5000) {
            $current = array_shift($queue);

            foreach (\Bitrix\Iblock\SectionTable::getList(array(
                'select' => array('ID'),
                'filter' => array('=IBLOCK_SECTION_ID' => $current),
                'limit' => 2000,
            ))->fetchAll() as $row) {
                $childId = (int)$row['ID'];
                if ($childId > 0 && !isset($seen[$childId])) {
                    $seen[$childId] = true;
                    $ids[] = $childId;
                    $queue[] = $childId;
                }
            }
        }

        return $ids;
    }
}
