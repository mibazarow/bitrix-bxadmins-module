<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Loader;

/**
 * Находит элементы без картинки: анонса, детальной или обеих.
 * Помогает агенту обработать ВСЕ подходящие объекты, а не первый попавшийся.
 */
class ListElementsWithoutImagesTool extends AbstractTool
{
    public const DOMAIN = 'content';

    private const MAX_LIMIT = 200;

    public static function getName(): string
    {
        return 'list_elements_without_images';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_elements_without_images',
            'description' => 'Находит элементы инфоблока (новости, товары) без картинки: без анонса, без детальной или без обеих. Используй перед set_element_images, чтобы обработать все подходящие элементы, а не часть.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'iblock_id' => array('type' => 'integer', 'description' => 'ID инфоблока. Без него — по всем инфоблокам.'),
                    'fields' => array(
                        'type' => 'string',
                        'description' => 'both (по умолчанию) — нет анонса или нет детальной, preview — нет анонса, detail — нет детальной.',
                    ),
                    'limit' => array('type' => 'integer', 'description' => 'Сколько вернуть, по умолчанию 50, максимум 200.'),
                ),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('iblock')) {
            return array('error' => 'Модуль iblock недоступен.');
        }

        $iblockId = (int)($args['iblock_id'] ?? 0);
        $mode = strtolower(trim((string)($args['fields'] ?? 'both')));
        $limit = max(1, min(self::MAX_LIMIT, (int)($args['limit'] ?? 50)));

        $filter = array('=ACTIVE' => 'Y');
        if ($iblockId > 0) {
            $filter['=IBLOCK_ID'] = $iblockId;
        }

        $result = array();
        $scan = 0;

        foreach (ElementTable::getList(array(
            'select' => array('ID', 'NAME', 'IBLOCK_ID', 'PREVIEW_PICTURE', 'DETAIL_PICTURE'),
            'filter' => $filter,
            'order' => array('ID' => 'DESC'),
            'limit' => $limit * 5,
        ))->fetchAll() as $row) {
            $scan++;

            $missing = array();
            if (in_array($mode, array('both', 'preview'), true) && (int)($row['PREVIEW_PICTURE'] ?? 0) <= 0) {
                $missing[] = 'preview';
            }
            if (in_array($mode, array('both', 'detail'), true) && (int)($row['DETAIL_PICTURE'] ?? 0) <= 0) {
                $missing[] = 'detail';
            }

            if ($missing === array()) {
                continue;
            }

            $result[] = array(
                'id' => (int)$row['ID'],
                'name' => (string)$row['NAME'],
                'iblock_id' => (int)$row['IBLOCK_ID'],
                'missing' => $missing,
            );

            if (count($result) >= $limit) {
                break;
            }
        }

        return array(
            'count' => count($result),
            'scanned' => $scan,
            'limit' => $limit,
            'elements' => $result,
            'note' => $result === array()
                ? 'Подходящих элементов нет.'
                : 'Передай все ID одним вызовом set_element_images (element_ids), чтобы поставить картинки одной операцией.',
        );
    }
}
