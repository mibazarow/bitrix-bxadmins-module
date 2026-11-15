<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Calendar\Internals\EventTable;
use Bitrix\Main\Loader;

/** Удаление события календаря. */
class DeleteCalendarEventTool extends AbstractChangeTool
{
    public const DOMAIN = 'collaboration';

    public static function getName(): string
    {
        return 'delete_calendar_event';
    }

    public static function rollbackNeedsRestore(): bool
    {
        // Удалённое событие нельзя вернуть тем же API: восстанавливаем сайт.
        return true;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_calendar_event',
            'description' => 'Удаляет событие календаря. Откат — восстановлением сайта.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'event_id' => array('type' => 'integer', 'description' => 'ID события'),
                ),
                'required' => array('event_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $eventId = (int)($args['event_id'] ?? 0);
        if ($eventId <= 0) {
            throw new \InvalidArgumentException('Параметр event_id обязателен.');
        }

        if (!Loader::includeModule('calendar')) {
            throw new \RuntimeException('Модуль calendar не установлен.');
        }

        $event = EventTable::getList(array(
            'select' => array('ID', 'NAME', 'DATE_FROM'),
            'filter' => array('=ID' => $eventId),
            'limit' => 1,
        ))->fetch();

        if (!$event) {
            throw new \InvalidArgumentException('Событие #' . $eventId . ' не найдено.');
        }

        return array(
            'summary' => 'Удаление события «' . $event['NAME'] . '»',
            'payload' => array('args' => array('event_id' => $eventId)),
            'preview' => 'Событие «' . $event['NAME'] . '» (' . $event['DATE_FROM'] . ') будет удалено.',
        );
    }

    public static function apply(array $payload): array
    {
        $eventId = (int)($payload['args']['event_id'] ?? 0);

        $deleted = \CCalendar::DeleteEvent($eventId, false, array('checkPermissions' => false));
        if ($deleted !== true) {
            throw new \RuntimeException('Не удалось удалить событие #' . $eventId . '.');
        }

        return array('event_id' => $eventId, 'deleted' => true);
    }

    public static function rollback(array $payload): void
    {
        // Восстанавливается через restore_site.
    }
}
