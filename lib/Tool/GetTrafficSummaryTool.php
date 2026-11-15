<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

/** Сводка посещаемости из модуля statistic. */
class GetTrafficSummaryTool extends AbstractTool
{
    public const DOMAIN = 'marketing';

    public static function getName(): string
    {
        return 'get_traffic_summary';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'get_traffic_summary',
            'description' => 'Посещаемость сайта за последние N дней (модуль statistic): хиты, хосты, сессии. Если модуль не собирает данные — честно скажет.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'days' => array('type' => 'integer', 'description' => 'За сколько дней, по умолчанию 7'),
                ),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('statistic')) {
            return array('error' => 'Модуль statistic не установлен. Установите его: install_module с module_id=statistic.');
        }

        $days = max(1, min(365, (int)($args['days'] ?? 7)));
        $connection = Application::getConnection();
        $since = date('Y-m-d', time() - $days * 86400);

        $summary = array('days' => $days, 'since' => $since);

        try {
            $summary['hits'] = (int)$connection->queryScalar(
                "SELECT COUNT(*) FROM b_stat_hit WHERE DATE_CREATE >= '" . $since . "'"
            );
        } catch (\Throwable $e) {
            $summary['hits'] = null;
        }

        try {
            $summary['sessions'] = (int)$connection->queryScalar(
                "SELECT COUNT(*) FROM b_stat_session WHERE DATE_FIRST >= '" . $since . "'"
            );
        } catch (\Throwable $e) {
            $summary['sessions'] = null;
        }

        try {
            $summary['visitors'] = (int)$connection->queryScalar(
                "SELECT COUNT(DISTINCT GUEST_ID) FROM b_stat_session WHERE DATE_FIRST >= '" . $since . "'"
            );
        } catch (\Throwable $e) {
            $summary['visitors'] = null;
        }

        $hasData = (int)($summary['hits'] ?? 0) > 0
            || (int)($summary['sessions'] ?? 0) > 0
            || (int)($summary['visitors'] ?? 0) > 0;

        $summary['note'] = $hasData
            ? null
            : 'Данных нет: модуль statistic не собирает статистику (выключен сбор или нет трафика).';

        return $summary;
    }
}
