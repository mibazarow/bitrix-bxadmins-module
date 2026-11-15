<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/** Баннеры рекламного модуля. */
class ListBannersTool extends AbstractTool
{
    public const DOMAIN = 'marketing';

    public static function getName(): string
    {
        return 'list_banners';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_banners',
            'description' => 'Рекламные баннеры (модуль advertising): ID, название, тип, активность, показы и клики.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'active_only' => array('type' => 'boolean', 'description' => 'Только активные'),
                ),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('advertising')) {
            return array('error' => 'Модуль advertising не установлен. Установите его: install_module с module_id=advertising.');
        }

        $filter = array();
        if (!empty($args['active_only'])) {
            $filter['ACTIVE'] = 'Y';
        }

        $banners = array();
        $list = \CAdvBanner::GetList($by = 's_id', $order = 'asc', $filter, false, false);

        while ($row = $list->Fetch()) {
            $banners[] = array(
                'id' => (int)$row['ID'],
                'name' => (string)($row['NAME'] ?? ''),
                'type' => (string)($row['TYPE_SID'] ?? ''),
                'active' => (string)($row['ACTIVE'] ?? '') === 'Y',
                'shows' => (int)($row['SHOWS'] ?? 0),
                'clicks' => (int)($row['CLICKS'] ?? 0),
                'weight' => (int)($row['WEIGHT'] ?? 0),
            );
        }

        return array('count' => count($banners), 'banners' => $banners);
    }
}
