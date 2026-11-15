<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Calendar\Internals\EventTable;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;

/**
 * Создание события календаря.
 *
 * Даты принимаются в ISO (YYYY-MM-DD HH:MM:SS), но легаси-API календаря понимает
 * только формат сайта (DD.MM.YYYY HH:MI:SS) — иначе молча создаётся событие
 * «на весь день» на текущую дату. Поэтому перед вызовом даты конвертируются.
 */
class CreateCalendarEventTool extends AbstractChangeTool
{
    public const DOMAIN = 'collaboration';

    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/';

    public static function getName(): string
    {
        return 'create_calendar_event';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_calendar_event',
            'description' => 'Создаёт событие в календаре пользователя. Даты в формате YYYY-MM-DD HH:MM:SS. Для события на весь день передайте all_day=true, а в to — последний день включительно.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'name' => array('type' => 'string', 'description' => 'Название события'),
                    'from' => array('type' => 'string', 'description' => 'Начало: YYYY-MM-DD HH:MM:SS'),
                    'to' => array('type' => 'string', 'description' => 'Конец: YYYY-MM-DD HH:MM:SS; для события на весь день — последний день включительно'),
                    'description' => array('type' => 'string', 'description' => 'Описание'),
                    'owner_id' => array('type' => 'integer', 'description' => 'ID владельца, по умолчанию текущий пользователь'),
                    'all_day' => array('type' => 'boolean', 'description' => 'Событие на весь день'),
                ),
                'required' => array('name', 'from', 'to'),
            ),
        );
    }

    public static function preview(array $args, array $context = array()): array
    {
        $name = IblockElementFields::name($args['name'] ?? null);
        $allDay = ($args['all_day'] ?? false) === true;

        $from = self::parseDate((string)($args['from'] ?? ''));
        $to = self::parseDate((string)($args['to'] ?? ''));

        if ($from === null || $to === null) {
            throw new \InvalidArgumentException('Даты должны быть в формате YYYY-MM-DD HH:MM:SS.');
        }

        if ($allDay) {
            // Для события «на весь день» to — последний день включительно, поэтому
            // однодневное событие имеет равные даты начала и конца.
            if ($to < $from) {
                throw new \InvalidArgumentException('Последний день события не может быть раньше первого.');
            }
        } elseif ($to <= $from) {
            throw new \InvalidArgumentException('Конец события должен быть позже начала.');
        }

        if (!Loader::includeModule('calendar')) {
            throw new \RuntimeException('Модуль calendar недоступен.');
        }

        // Демон вызывает инструмент без сессии, поэтому пользователь — из контекста.
        $ownerId = (int)($args['owner_id'] ?? 0)
            ?: (int)($context['user_id'] ?? 0)
            ?: (int)\Bitrix\Main\Engine\CurrentUser::get()->getId();

        $owner = $ownerId > 0
            ? UserTable::getList(array(
                'select' => array('ID'),
                'filter' => array('=ID' => $ownerId, '=ACTIVE' => 'Y'),
                'limit' => 1,
            ))->fetch()
            : false;

        if (!$owner) {
            throw new \InvalidArgumentException('Пользователь #' . $ownerId . ' не найден или заблокирован.');
        }

        $payload = array(
            'name' => $name,
            'from' => $from,
            'to' => $to,
            'description' => mb_substr(trim((string)($args['description'] ?? '')), 0, 5000),
            'owner_id' => $ownerId,
            'all_day' => $allDay,
        );

        return array(
            'summary' => 'Событие «' . $name . '»',
            'payload' => array('args' => $payload),
            'preview' => 'Новое событие «' . $name . '» с ' . $from . ' по ' . $to
                . ($allDay ? ' (весь день)' : '')
                . ($payload['description'] !== '' ? "\n" . $payload['description'] : ''),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $allDay = !empty($args['all_day']);
        $from = (string)($args['from'] ?? '');
        $to = (string)($args['to'] ?? '');

        $arFields = array(
            'CAL_TYPE' => 'user',
            'OWNER_ID' => (int)$args['owner_id'],
            'NAME' => (string)$args['name'],
            'DESCRIPTION' => (string)$args['description'],
            'DATE_FROM' => self::toSiteDate($from, $allDay),
            'DATE_TO' => self::toSiteDate($to, $allDay),
            // Строго boolean: строка 'N' в легаси-API считается истиной и превращает
            // событие в «на весь день».
            'SKIP_TIME' => $allDay,
        );

        // Легаси-исключение: создание события с участниками и напоминаниями — только CCalendar.
        $id = \CCalendar::SaveEvent(array(
            'autoDetectSection' => true,
            // Права проверяет сам харнес (доступ только у администратора), а сессии в демоне нет.
            'checkPermission' => false,
            'userId' => (int)$args['owner_id'],
            'arFields' => $arFields,
        ));

        if (!is_int($id) || $id <= 0) {
            $message = is_string($id) ? strip_tags($id) : '';
            throw new \RuntimeException(
                'Не удалось создать событие календаря' . ($message !== '' ? ': ' . $message : '.')
            );
        }

        return array('event_id' => (int)$id, 'name' => (string)$args['name']);
    }

    public static function rollback(array $payload): void
    {
        $eventId = (int)($payload['result']['event_id'] ?? 0);
        if ($eventId <= 0) {
            return;
        }

        if (!Loader::includeModule('calendar')) {
            throw new \RuntimeException('Модуль calendar недоступен — событие не удалено.');
        }

        $event = EventTable::getList(array(
            'select' => array('ID', 'DELETED'),
            'filter' => array('=ID' => $eventId),
            'limit' => 1,
        ))->fetch();

        // Уже удалено — откат повторно ничего не ломает.
        if (!$event || $event['DELETED'] === 'Y') {
            return;
        }

        // Второй параметр — синхронизация с внешними календарями, а не права.
        // В демоне нет сессии, поэтому проверку прав отключаем явно.
        $deleted = \CCalendar::DeleteEvent($eventId, false, array('checkPermissions' => false));

        if ($deleted !== true) {
            throw new \RuntimeException('Не удалось удалить событие календаря #' . $eventId . '.');
        }
    }

    /** ISO → формат сайта; null, если дата не разбирается. */
    private static function parseDate(string $value): ?string
    {
        $value = trim($value);

        if (preg_match(self::DATE_PATTERN, $value) !== 1) {
            return null;
        }

        $time = strtotime($value);

        return $time === false ? null : date('Y-m-d H:i:s', $time);
    }

    /** Легаси-API календаря принимает даты в формате сайта (DD.MM.YYYY[ HH:MI:SS]). */
    private static function toSiteDate(string $isoDate, bool $allDay): string
    {
        $time = strtotime($isoDate);
        if ($time === false) {
            throw new \InvalidArgumentException('Некорректная дата события.');
        }

        return $allDay ? date('d.m.Y', $time) : date('d.m.Y H:i:s', $time);
    }
}
