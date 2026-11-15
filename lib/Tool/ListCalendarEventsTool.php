<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Calendar\Internals\EventTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

/** События календаря. */
class ListCalendarEventsTool extends AbstractTool
{
    public const DOMAIN = 'collaboration';

    private const LIMIT = 100;

    public static function getName(): string
    {
        return 'list_calendar_events';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_calendar_events',
            'description' => 'События календаря: ID, название, начало, конец, владелец. По умолчанию ближайшие 30 дней. Максимум 100.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'owner_id' => array('type' => 'integer', 'description' => 'Только события этого пользователя'),
                    'days_ahead' => array('type' => 'integer', 'description' => 'Горизонт в днях (по умолчанию 30)'),
                ),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,events:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('calendar')) {
            return array('error' => 'Модуль calendar недоступен.');
        }

        $days = max(1, min(365, (int)($args['days_ahead'] ?? 30)));
        $from = DateTime::createFromPhp(new \DateTime('today'));
        $to = DateTime::createFromPhp(new \DateTime('+' . $days . ' days'));

        $filter = array('>=DATE_FROM' => $from, '<=DATE_FROM' => $to, '=DELETED' => 'N');
        if (($args['owner_id'] ?? 0) > 0) {
            $filter['=OWNER_ID'] = (int)$args['owner_id'];
        }

        $rows = EventTable::getList(array(
            'select' => array('ID', 'NAME', 'DATE_FROM', 'DATE_TO', 'OWNER_ID', 'CAL_TYPE', 'ACCESSIBILITY'),
            'filter' => $filter,
            'order' => array('DATE_FROM' => 'ASC'),
            'limit' => self::LIMIT + 1,
        ))->fetchAll();

        $truncated = count($rows) > self::LIMIT;
        $events = array();

        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            $events[] = array(
                'id' => (int)$row['ID'],
                'name' => (string)$row['NAME'],
                'from' => $row['DATE_FROM'] instanceof \Bitrix\Main\Type\Date ? $row['DATE_FROM']->toString() : (string)$row['DATE_FROM'],
                'to' => $row['DATE_TO'] instanceof \Bitrix\Main\Type\Date ? $row['DATE_TO']->toString() : (string)$row['DATE_TO'],
                'owner_id' => (int)$row['OWNER_ID'],
                'cal_type' => (string)$row['CAL_TYPE'],
            );
        }

        return array('count' => count($events), 'truncated' => $truncated, 'events' => $events);
    }
}
