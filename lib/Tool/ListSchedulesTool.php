<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\ScheduleService;

/** Задачи по расписанию: что агент делает сам и когда. */
class ListSchedulesTool extends AbstractTool
{
    public const DOMAIN = 'automation';

    public static function getName(): string
    {
        return 'list_schedules';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_schedules',
            'description' => 'Задачи по расписанию: ID, название, что делает, интервал, когда следующий запуск, результат последнего. Создать — create_schedule.',
            'parameters' => array('type' => 'object', 'properties' => new \stdClass()),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $schedules = ScheduleService::list((int)($context['user_id'] ?? 0));

        $items = array();
        foreach ($schedules as $schedule) {
            $items[] = array(
                'id' => $schedule['id'],
                'title' => $schedule['title'],
                'prompt' => $schedule['prompt'],
                'interval' => $schedule['interval'],
                'interval_text' => $schedule['interval_text'],
                'active' => $schedule['active'],
                'next_run' => $schedule['next_run'],
                'last_run' => $schedule['last_run'],
                'last_status' => $schedule['last_status'],
                'last_result' => $schedule['last_result'],
                'runs' => $schedule['runs'],
            );
        }

        return array('count' => count($items), 'schedules' => $items);
    }
}
