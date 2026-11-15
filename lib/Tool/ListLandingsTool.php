<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/** Лендинги (конструктор). */
class ListLandingsTool extends AbstractTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'list_landings';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_landings',
            'description' => 'Лендинги конструктора (модуль landing): ID, название, код, активность, адрес.',
            'parameters' => array('type' => 'object', 'properties' => new \stdClass()),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('landing')) {
            return array('error' => 'Модуль landing не установлен. Установите его: install_module с module_id=landing.');
        }

        $landings = array();

        $rows = \Bitrix\Landing\Landing::getList(array(
            'select' => array('ID', 'TITLE', 'CODE', 'ACTIVE', 'SITE_ID'),
            'limit' => 100,
        ));

        foreach ($rows as $row) {
            $landings[] = array(
                'id' => (int)($row['ID'] ?? 0),
                'title' => (string)($row['TITLE'] ?? ''),
                'code' => (string)($row['CODE'] ?? ''),
                'active' => !empty($row['ACTIVE']),
                'site_id' => (int)($row['SITE_ID'] ?? 0),
            );
        }

        return array('count' => count($landings), 'landings' => $landings);
    }
}
