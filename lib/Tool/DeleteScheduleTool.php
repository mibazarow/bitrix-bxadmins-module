<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Type\DateTime;
use Mibazarow\Bxadmins\Model\ScheduleTable;
use Mibazarow\Bxadmins\Service\ScheduleService;

/** Удаление задачи по расписанию. */
class DeleteScheduleTool extends AbstractChangeTool
{
    public const DOMAIN = 'automation';

    public static function getName(): string
    {
        return 'delete_schedule';
    }

    public static function needsSqlDump(): bool
    {
        return false;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_schedule',
            'description' => 'Удаляет задачу по расписанию. Чат с историей запусков остаётся.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'schedule_id' => array('type' => 'integer', 'description' => 'ID задачи'),
                ),
                'required' => array('schedule_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $scheduleId = (int)($args['schedule_id'] ?? 0);
        $schedule = ScheduleService::get($scheduleId);

        if ($schedule === null) {
            throw new \InvalidArgumentException('Задача #' . $scheduleId . ' не найдена.');
        }

        $chat = \Mibazarow\Bxadmins\Model\ChatTable::getById($schedule['chat_id'])->fetch();

        return array(
            'summary' => 'Удаление задачи «' . $schedule['title'] . '»',
            'payload' => array(
                'args' => array('schedule_id' => $scheduleId),
                'before' => array(
                    'row' => ScheduleService::raw($scheduleId),
                    'chat' => $chat ?: array(),
                ),
            ),
            'preview' => 'Задача «' . $schedule['title'] . '» (' . $schedule['interval_text']
                . ') будет удалена вместе с чатом запусков'
                . ($chat ? ' «' . (string)$chat['TITLE'] . '»' : '')
                . '. Запусков было: ' . $schedule['runs'] . '.',
        );
    }

    public static function apply(array $payload): array
    {
        $scheduleId = (int)($payload['args']['schedule_id'] ?? 0);

        // Вместе с чатом: задача без чата выполняться не сможет.
        ScheduleService::delete($scheduleId, true);

        return array('schedule_id' => $scheduleId, 'deleted' => true);
    }

    public static function rollback(array $payload): void
    {
        $row = (array)($payload['before']['row'] ?? array());
        if ($row === array()) {
            return;
        }

        // Сначала чат: без него задача снова станет «осиротевшей».
        $chat = (array)($payload['before']['chat'] ?? array());

        if ($chat !== array()) {
            $connection = \Bitrix\Main\Application::getConnection();
            $chatTable = \Mibazarow\Bxadmins\Model\ChatTable::getTableName();

            $exists = (int)$connection->queryScalar(
                'SELECT COUNT(*) FROM ' . $chatTable . ' WHERE ID = ' . (int)$chat['ID']
            );

            if ($exists === 0) {
                // CREATED_AT обязателен, а в снимке он приходит строкой из ORM-фетча.
                foreach (array('CREATED_AT', 'UPDATED_AT') as $field) {
                    $value = $chat[$field] ?? null;
                    $chat[$field] = $value instanceof \Bitrix\Main\Type\Date
                        ? $value
                        : new DateTime();
                }

                $connection->add($chatTable, $chat);
            }
        }

        // Возвращаем строку с прежним ID: на него могут ссылаться внешние записи.
        // Даты из снимка приходят строками — ORM ждёт объекты Date/DateTime.
        foreach (array('CREATED_AT', 'UPDATED_AT', 'NEXT_RUN', 'LAST_RUN') as $field) {
            if (!array_key_exists($field, $row)) {
                continue;
            }

            $value = $row[$field];

            if ($value instanceof \Bitrix\Main\Type\Date) {
                continue;
            }

            // NEXT_RUN и LAST_RUN могут быть пустыми, CREATED_AT и UPDATED_AT — нет.
            $required = in_array($field, array('CREATED_AT', 'UPDATED_AT'), true);

            if ($value === null || $value === '') {
                $row[$field] = $required ? new DateTime() : null;

                continue;
            }

            try {
                $row[$field] = new DateTime((string)$value);
            } catch (\Throwable $e) {
                $row[$field] = $required ? new DateTime() : null;
            }
        }

        // ORM-добавление не сохраняет явный ID, поэтому пишем строку напрямую:
        // на прежний ID могут ссылаться записи журнала и истории.
        $added = ScheduleTable::add($row);

        if (!$added->isSuccess() && isset($row['ID'])) {
            \Bitrix\Main\Application::getConnection()->add(
                ScheduleTable::getTableName(),
                $row
            );
        }
    }
}
