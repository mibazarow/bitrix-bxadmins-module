<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Calendar\Internals\EventTable;
use Bitrix\Main\Loader;

/**
 * Правка события календаря.
 *
 * Даты передаются в ISO, а легаси-API календаря ждёт формат сайта — конвертируем
 * так же, как при создании события.
 */
class UpdateCalendarEventTool extends AbstractChangeTool
{
    public const DOMAIN = 'collaboration';

    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/';

    public static function getName(): string
    {
        return 'update_calendar_event';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_calendar_event',
            'description' => 'Меняет событие календаря: название, даты, описание. Список — list_calendar_events.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'event_id' => array('type' => 'integer', 'description' => 'ID события'),
                    'name' => array('type' => 'string', 'description' => 'Новое название'),
                    'from' => array('type' => 'string', 'description' => 'Начало: YYYY-MM-DD HH:MM:SS'),
                    'to' => array('type' => 'string', 'description' => 'Конец: YYYY-MM-DD HH:MM:SS'),
                    'description' => array('type' => 'string', 'description' => 'Описание'),
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
            'select' => array('ID', 'NAME', 'DATE_FROM', 'DATE_TO', 'DESCRIPTION', 'OWNER_ID', 'CAL_TYPE', 'DT_SKIP_TIME'),
            'filter' => array('=ID' => $eventId),
            'limit' => 1,
        ))->fetch();

        if (!$event) {
            throw new \InvalidArgumentException('Событие #' . $eventId . ' не найдено.');
        }

        $fields = array();
        $lines = array();

        if (array_key_exists('name', $args)) {
            $name = trim((string)$args['name']);
            if ($name !== '' && $name !== (string)$event['NAME']) {
                $fields['NAME'] = $name;
                $lines[] = 'Название: ' . $event['NAME'] . ' → ' . $name;
            }
        }

        if (array_key_exists('from', $args)) {
            $from = self::parseDate((string)$args['from']);
            if ($from === null) {
                throw new \InvalidArgumentException('Дата начала — YYYY-MM-DD HH:MM:SS.');
            }

            $fields['DATE_FROM'] = self::toSiteDate($from);
            $lines[] = 'Начало: ' . $event['DATE_FROM'] . ' → ' . $from;
        }

        if (array_key_exists('to', $args)) {
            $to = self::parseDate((string)$args['to']);
            if ($to === null) {
                throw new \InvalidArgumentException('Дата конца — YYYY-MM-DD HH:MM:SS.');
            }

            $fields['DATE_TO'] = self::toSiteDate($to);
            $lines[] = 'Конец: ' . $event['DATE_TO'] . ' → ' . $to;
        }

        if (array_key_exists('description', $args)) {
            $description = trim((string)$args['description']);
            if ($description !== (string)($event['DESCRIPTION'] ?? '')) {
                $fields['DESCRIPTION'] = $description;
                $lines[] = 'Описание обновлено';
            }
        }

        if ($fields === array()) {
            throw new \InvalidArgumentException('Изменений нет: переданные значения совпадают с текущими.');
        }

        return array(
            'summary' => 'Событие #' . $eventId . ': ' . implode(', ', array_keys($fields)),
            'payload' => array(
                'args' => array(
                    'event_id' => $eventId,
                    'fields' => $fields,
                    'owner_id' => (int)$event['OWNER_ID'],
                    'cal_type' => (string)$event['CAL_TYPE'],
                ),
                'before' => array(
                    'NAME' => (string)$event['NAME'],
                    'DATE_FROM' => (string)$event['DATE_FROM'],
                    'DATE_TO' => (string)$event['DATE_TO'],
                    'DESCRIPTION' => (string)($event['DESCRIPTION'] ?? ''),
                ),
            ),
            'preview' => 'Событие «' . $event['NAME'] . "\"\n" . implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $eventId = (int)($args['event_id'] ?? 0);
        $fields = (array)($args['fields'] ?? array());

        // Легаси-исключение: правка события с пересчётом календарей — только CCalendar.
        $result = \CCalendar::SaveEvent(array(
            'id' => $eventId,
            'autoDetectSection' => false,
            'checkPermission' => false,
            'userId' => (int)$args['owner_id'],
            'arFields' => array_merge($fields, array(
                'ID' => $eventId,
                'CAL_TYPE' => (string)$args['cal_type'],
                'OWNER_ID' => (int)$args['owner_id'],
            )),
        ));

        if (!is_int($result) || $result <= 0) {
            throw new \RuntimeException(
                'Не удалось изменить событие' . (is_string($result) ? ': ' . strip_tags($result) : '.')
            );
        }

        return array('event_id' => $result, 'updated' => array_keys($fields));
    }

    public static function rollback(array $payload): void
    {
        $args = (array)($payload['args'] ?? array());
        $before = (array)($payload['before'] ?? array());

        if ((int)($args['event_id'] ?? 0) <= 0 || $before === array()) {
            return;
        }

        \CCalendar::SaveEvent(array(
            'id' => (int)$args['event_id'],
            'autoDetectSection' => false,
            'checkPermission' => false,
            'userId' => (int)$args['owner_id'],
            'arFields' => array_merge($before, array(
                'ID' => (int)$args['event_id'],
                'CAL_TYPE' => (string)$args['cal_type'],
                'OWNER_ID' => (int)$args['owner_id'],
            )),
        ));
    }

    private static function parseDate(string $value): ?string
    {
        $value = trim($value);

        if (preg_match(self::DATE_PATTERN, $value) !== 1) {
            return null;
        }

        $time = strtotime($value);

        return $time === false ? null : date('Y-m-d H:i:s', $time);
    }

    /** Легаси-API календаря принимает даты в формате сайта. */
    private static function toSiteDate(string $isoDate): string
    {
        $time = strtotime($isoDate);

        return $time === false ? $isoDate : date('d.m.Y H:i:s', $time);
    }
}
