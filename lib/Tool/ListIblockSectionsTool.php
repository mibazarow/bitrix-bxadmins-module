<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Loader;

/** Разделы инфоблока. */
class ListIblockSectionsTool extends AbstractTool
{
    public const DOMAIN = 'content';

    private const LIMIT = 200;

    public static function getName(): string
    {
        return 'list_iblock_sections';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_iblock_sections',
            'description' => 'Разделы инфоблока: ID, код, название, родитель, активность. Максимум 200.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'offset' => array('type' => 'integer', 'description' => 'Пропустить N записей (для следующей страницы)'),
                    'iblock_id' => array('type' => 'integer', 'description' => 'ID инфоблока'),
                    'parent_id' => array('type' => 'integer', 'description' => 'Только дочерние разделы этого раздела'),
                ),
                'required' => array('iblock_id'),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,sections:array}|array{error:string} */
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
        if (isset($args['parent_id'])) {
            $filter['=IBLOCK_SECTION_ID'] = (int)$args['parent_id'];
        }

        $rows = SectionTable::getList(array(
            'select' => array('ID', 'CODE', 'NAME', 'IBLOCK_SECTION_ID', 'ACTIVE', 'SORT', 'DEPTH_LEVEL'),
            'filter' => $filter,
            'order' => array('LEFT_MARGIN' => 'ASC', 'SORT' => 'ASC'),
            'offset' => max(0, (int)($args['offset'] ?? 0)),
            'limit' => self::LIMIT + 1,
        ))->fetchAll();

        $truncated = count($rows) > self::LIMIT;
        $sections = array();

        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            $sections[] = array(
                'id' => (int)$row['ID'],
                'code' => (string)($row['CODE'] ?? ''),
                'name' => (string)$row['NAME'],
                'parent_id' => (int)($row['IBLOCK_SECTION_ID'] ?? 0),
                'active' => (string)$row['ACTIVE'] === 'Y',
                'sort' => (int)$row['SORT'],
                'depth' => (int)$row['DEPTH_LEVEL'],
            );
        }

        return array('count' => count($sections), 'truncated' => $truncated, 'sections' => $sections);
    }
}
