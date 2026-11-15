<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Loader;

/** Элементы инфоблока: список с фильтрами. */
class ListIblockElementsTool extends AbstractTool
{
    public const DOMAIN = 'content';

    private const LIMIT = 100;

    public static function getName(): string
    {
        return 'list_iblock_elements';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_iblock_elements',
            'description' => 'Элементы инфоблока: ID, код, название, активность, раздел. Можно искать по подстроке названия и фильтровать по разделу. Максимум 100.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'offset' => array('type' => 'integer', 'description' => 'Пропустить N записей (для следующей страницы)'),
                    'iblock_id' => array('type' => 'integer', 'description' => 'ID инфоблока'),
                    'section_id' => array('type' => 'integer', 'description' => 'ID раздела'),
                    'search' => array('type' => 'string', 'description' => 'Подстрока названия'),
                    'active_only' => array('type' => 'boolean', 'description' => 'Только активные (по умолчанию да)'),
                ),
                'required' => array('iblock_id'),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,elements:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $iblockId = (int)($args['iblock_id'] ?? 0);
        if ($iblockId <= 0) {
            return array('error' => 'Параметр iblock_id обязателен.');
        }

        if (!Loader::includeModule('iblock')) {
            return array('error' => 'Модуль iblock недоступен.');
        }

        $filter = array('=IBLOCK_ID' => $iblockId);
        if (isset($args['section_id'])) {
            $filter['=IBLOCK_SECTION_ID'] = (int)$args['section_id'];
        }
        if (($args['active_only'] ?? true) !== false) {
            $filter['=ACTIVE'] = 'Y';
        }

        $search = trim((string)($args['search'] ?? ''));
        if ($search !== '') {
            $filter['%NAME'] = $search;
        }

        $rows = ElementTable::getList(array(
            'select' => array('ID', 'CODE', 'NAME', 'ACTIVE', 'IBLOCK_SECTION_ID', 'SORT', 'TIMESTAMP_X'),
            'filter' => $filter,
            'order' => array('SORT' => 'ASC', 'ID' => 'ASC'),
            'offset' => max(0, (int)($args['offset'] ?? 0)),
            'limit' => self::LIMIT + 1,
        ))->fetchAll();

        $truncated = count($rows) > self::LIMIT;
        $elements = array();

        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            $elements[] = array(
                'id' => (int)$row['ID'],
                'code' => (string)($row['CODE'] ?? ''),
                'name' => (string)$row['NAME'],
                'active' => (string)$row['ACTIVE'] === 'Y',
                'section_id' => (int)($row['IBLOCK_SECTION_ID'] ?? 0),
                'sort' => (int)$row['SORT'],
            );
        }

        return array('count' => count($elements), 'truncated' => $truncated, 'elements' => $elements);
    }
}
