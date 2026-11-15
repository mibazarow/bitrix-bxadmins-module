<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\ScheduleService;

/** Правка задачи по расписанию: текст, интервал, включение. */
class UpdateScheduleTool extends AbstractChangeTool
{
    public const DOMAIN = 'automation';

    public static function getName(): string
    {
        return 'update_schedule';
    }

    public static function needsSqlDump(): bool
    {
        return false;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_schedule',
            'description' => 'Меняет задачу по расписанию: название, что делает, как часто, включена ли. Список — list_schedules.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'schedule_id' => array('type' => 'integer', 'description' => 'ID задачи'),
                    'title' => array('type' => 'string', 'description' => 'Новое название'),
                    'prompt' => array('type' => 'string', 'description' => 'Новый текст задачи'),
                    'interval' => array('type' => 'string', 'description' => 'Новый интервал: «каждые 2 часа»'),
                    'interval_seconds' => array('type' => 'integer', 'description' => 'Интервал в секундах'),
                    'active' => array('type' => 'boolean', 'description' => 'Включить (true) или выключить (false)'),
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

        $fields = array();
        $lines = array();

        if (array_key_exists('title', $args) && trim((string)$args['title']) !== '') {
            $title = trim((string)$args['title']);
            if ($title !== $schedule['title']) {
                $fields['title'] = $title;
                $lines[] = 'Название: ' . $schedule['title'] . ' → ' . $title;
            }
        }

        if (array_key_exists('prompt', $args) && trim((string)$args['prompt']) !== '') {
            $prompt = trim((string)$args['prompt']);
            if ($prompt !== $schedule['prompt']) {
                $fields['prompt'] = $prompt;
                $lines[] = 'Текст задачи обновлён';
            }
        }

        $interval = null;
        if (!empty($args['interval_seconds'])) {
            $interval = ScheduleService::normalizeInterval((int)$args['interval_seconds']);
        } elseif (trim((string)($args['interval'] ?? '')) !== '') {
            $interval = ScheduleService::parseInterval((string)$args['interval']);
            if ($interval === null) {
                throw new \InvalidArgumentException('Не понял интервал «' . (string)$args['interval'] . '».');
            }
        }

        if ($interval !== null && $interval !== $schedule['interval']) {
            $fields['interval'] = $interval;
            $lines[] = 'Интервал: ' . $schedule['interval_text'] . ' → ' . ScheduleService::humanInterval($interval);
        }

        if (array_key_exists('active', $args)) {
            $active = (bool)$args['active'];
            if ($active !== $schedule['active']) {
                $fields['active'] = $active;
                $lines[] = $active ? 'Задача включена' : 'Задача выключена';
            }
        }

        if ($fields === array()) {
            throw new \InvalidArgumentException('Изменений нет: задача уже в этом состоянии.');
        }

        return array(
            'summary' => 'Задача «' . $schedule['title'] . '»: ' . implode(', ', array_keys($fields)),
            'payload' => array(
                'args' => array('schedule_id' => $scheduleId, 'fields' => $fields),
                'before' => array(
                    'title' => $schedule['title'],
                    'prompt' => $schedule['prompt'],
                    'interval' => $schedule['interval'],
                    'active' => $schedule['active'],
                ),
            ),
            'preview' => 'Задача по расписанию «' . $schedule['title'] . "\"\n" . implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $scheduleId = (int)($payload['args']['schedule_id'] ?? 0);
        $fields = (array)($payload['args']['fields'] ?? array());

        ScheduleService::update($scheduleId, $fields);

        return array('schedule_id' => $scheduleId, 'updated' => array_keys($fields));
    }

    public static function rollback(array $payload): void
    {
        $scheduleId = (int)($payload['args']['schedule_id'] ?? 0);
        $before = (array)($payload['before'] ?? array());

        if ($scheduleId > 0 && $before !== array()) {
            ScheduleService::update($scheduleId, $before);
        }
    }
}
