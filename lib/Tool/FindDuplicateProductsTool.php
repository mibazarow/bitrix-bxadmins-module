<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

/**
 * Поиск дублей товаров/элементов по названию, коду или XML_ID. Только чтение.
 */
class FindDuplicateProductsTool extends AbstractTool
{
    public const DOMAIN = 'commerce';

    private const MAX_GROUPS = 50;
    private const IDS_PER_GROUP = 10;

    public static function getName(): string
    {
        return 'find_duplicates';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'find_duplicates',
            'description' => 'Ищет дубли элементов по названию, символьному коду или XML_ID: группы с одинаковым значением и ID. Только чтение; объединение — вручную.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'iblock_id' => array('type' => 'integer', 'description' => 'ID инфоблока; без него — по всем инфоблокам'),
                    'by' => array(
                        'type' => 'string',
                        'enum' => array('name', 'code', 'xml_id'),
                        'description' => 'Поле сравнения, по умолчанию name',
                    ),
                    'active_only' => array('type' => 'boolean', 'description' => 'Только активные, по умолчанию да'),
                ),
            ),
        );
    }

    /** @return array<string,mixed>|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('iblock')) {
            return array('error' => 'Модуль iblock недоступен.');
        }

        $by = strtolower(trim((string)($args['by'] ?? 'name')));
        $field = array('name' => 'NAME', 'code' => 'CODE', 'xml_id' => 'XML_ID')[$by] ?? null;

        if ($field === null) {
            return array('error' => 'by должен быть name, code или xml_id.');
        }

        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $iblockId = max(0, (int)($args['iblock_id'] ?? 0));
        $activeOnly = !array_key_exists('active_only', $args) || !empty($args['active_only']);

        $where = array($field . " IS NOT NULL", $field . " <> ''");
        if ($iblockId > 0) {
            $where[] = 'IBLOCK_ID = ' . $iblockId;
        }
        if ($activeOnly) {
            $where[] = "ACTIVE = 'Y'";
        }

        $sql = 'SELECT ' . $field . ' AS VAL, COUNT(*) AS CNT'
            . ' FROM b_iblock_element WHERE ' . implode(' AND ', $where)
            . ' GROUP BY ' . $field . ' HAVING COUNT(*) > 1 ORDER BY CNT DESC LIMIT ' . self::MAX_GROUPS;

        $rows = $connection->query($sql)->fetchAll();
        $groups = array();

        foreach ($rows as $row) {
            $value = (string)$row['VAL'];
            $safe = $helper->forSql($value);
            $ids = $connection->query(
                'SELECT ID, IBLOCK_ID, NAME FROM b_iblock_element WHERE ' . $field . " = '" . $safe . "'"
                . ($iblockId > 0 ? ' AND IBLOCK_ID = ' . $iblockId : '')
                . ' ORDER BY ID ASC LIMIT ' . self::IDS_PER_GROUP
            )->fetchAll();

            $groups[] = array(
                'value' => $value,
                'count' => (int)$row['CNT'],
                'items' => array_map(static function (array $item): array {
                    return array(
                        'id' => (int)$item['ID'],
                        'iblock_id' => (int)$item['IBLOCK_ID'],
                        'name' => (string)$item['NAME'],
                    );
                }, $ids),
            );
        }

        return array(
            'by' => $by,
            'iblock_id' => $iblockId,
            'groups' => $groups,
            'count' => count($groups),
            'truncated' => count($rows) >= self::MAX_GROUPS,
        );
    }
}
