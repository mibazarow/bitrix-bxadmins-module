<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\SiteTable;
use Bitrix\Main\SiteTemplateTable;

/**
 * Сайты установки и их шаблоны.
 */
class ListSitesTool extends AbstractTool
{
    public const DOMAIN = 'core';

    private const LIMIT = 100;

    public static function getName(): string
    {
        return 'list_sites';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_sites',
            'description' => 'Список сайтов установки: LID, название, сайт по умолчанию, активность, сортировка, каталог, домен, шаблон. Максимум 100.',
            'parameters' => array(
                'type' => 'object',
                'properties' => new \stdClass(),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,sites:array} */
    public static function execute(array $args, array $context = array()): array
    {
        $rows = SiteTable::getList(array(
            'select' => array('LID', 'NAME', 'DEF', 'ACTIVE', 'SORT', 'DIR', 'SERVER_NAME'),
            'order' => array('SORT' => 'ASC', 'LID' => 'ASC'),
            'limit' => self::LIMIT + 1,
        ))->fetchAll();

        $truncated = count($rows) > self::LIMIT;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::LIMIT);
        }

        $templates = self::templatesBySite();
        $sites = array();

        foreach ($rows as $row) {
            $lid = (string)$row['LID'];
            $sites[] = array(
                'lid' => $lid,
                'name' => (string)$row['NAME'],
                'def' => $row['DEF'] === 'Y' || $row['DEF'] === true || $row['DEF'] === 1,
                'active' => $row['ACTIVE'] === 'Y' || $row['ACTIVE'] === true || $row['ACTIVE'] === 1,
                'sort' => (int)$row['SORT'],
                'dir' => (string)($row['DIR'] ?? ''),
                'server_name' => (string)($row['SERVER_NAME'] ?? ''),
                'template_id' => $templates[$lid] ?? '',
            );
        }

        return array(
            'count' => count($sites),
            'truncated' => $truncated,
            'sites' => $sites,
        );
    }

    /** @return array<string,string> LID => шаблон (по умолчанию — с пустым условием) */
    private static function templatesBySite(): array
    {
        $templates = array();

        $rows = SiteTemplateTable::getList(array(
            'select' => array('SITE_ID', 'TEMPLATE', 'CONDITION'),
            'order' => array('SORT' => 'ASC', 'ID' => 'ASC'),
            'limit' => 1000,
        ))->fetchAll();

        foreach ($rows as $row) {
            $siteId = (string)$row['SITE_ID'];
            $condition = trim((string)($row['CONDITION'] ?? ''));

            // Шаблон по умолчанию (без условия) приоритетнее условных.
            if ($condition === '' || !isset($templates[$siteId])) {
                $templates[$siteId] = (string)$row['TEMPLATE'];
            }
        }

        return $templates;
    }
}
