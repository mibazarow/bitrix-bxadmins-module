<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\ScheduleService;

/** Новая задача по расписанию. */
class CreateScheduleTool extends AbstractChangeTool
{
    public const DOMAIN = 'automation';

    public static function getName(): string
    {
        return 'create_schedule';
    }

    public static function needsSqlDump(): bool
    {
        // Расписание — настройка харнеса, а не данные сайта: дамп не нужен.
        return false;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_schedule',
            'description' => 'Ставит задачу по расписанию: агент будет сам выполнять её с заданным интервалом. Интервал — по-русски («каждые 3 часа», «раз в день») или в секундах. Запуски идут в отдельном чате задачи.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'title' => array('type' => 'string', 'description' => 'Короткое название, 2–5 слов'),
                    'prompt' => array(
                        'type' => 'string',
                        'description' => 'Что именно делать при каждом запуске: объекты, условия, действие',
                    ),
                    'interval' => array(
                        'type' => 'string',
                        'description' => 'Как часто: «каждые 3 часа», «раз в день», «каждые 30 минут»',
                    ),
                    'interval_seconds' => array(
                        'type' => 'integer',
                        'description' => 'То же в секундах (если не указан interval). Минимум 300',
                    ),
                ),
                'required' => array('title', 'prompt'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $title = trim((string)($args['title'] ?? ''));
        $prompt = trim((string)($args['prompt'] ?? ''));

        if ($title === '') {
            throw new \InvalidArgumentException('Нужно название задачи.');
        }

        if (mb_strlen($prompt) < 10) {
            throw new \InvalidArgumentException('Опишите, что именно делать при каждом запуске.');
        }

        $interval = self::interval($args);

        return array(
            'summary' => 'Задача по расписанию «' . $title . '»',
            'payload' => array(
                'args' => array(
                    'title' => mb_substr($title, 0, 255),
                    'prompt' => $prompt,
                    'interval' => $interval,
                ),
            ),
            'preview' => 'Новая задача по расписанию: «' . $title . "»\n\n"
                . 'Что делает: ' . mb_substr($prompt, 0, 600) . "\n\n"
                . 'Когда: ' . ScheduleService::humanInterval($interval)
                . '. Первый запуск — через ' . ScheduleService::humanInterval($interval) . '.',
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $userId = (int)($payload['user_id'] ?? 0) ?: 1;

        $created = ScheduleService::create(
            $userId,
            (string)$args['title'],
            (string)$args['prompt'],
            (int)$args['interval']
        );

        return array('schedule_id' => $created['id'], 'chat_id' => $created['chat_id']);
    }

    public static function rollback(array $payload): void
    {
        $scheduleId = (int)($payload['result']['schedule_id'] ?? 0);
        if ($scheduleId > 0) {
            ScheduleService::delete($scheduleId);
        }
    }

    /** @param array $args */
    private static function interval(array $args): int
    {
        $seconds = (int)($args['interval_seconds'] ?? 0);
        if ($seconds > 0) {
            return ScheduleService::normalizeInterval($seconds);
        }

        $text = trim((string)($args['interval'] ?? ''));
        $parsed = ScheduleService::parseInterval($text);

        if ($parsed === null) {
            throw new \InvalidArgumentException(
                'Не понял интервал «' . $text . '». Примеры: «каждые 3 часа», «раз в день», «каждые 30 минут».'
            );
        }

        return $parsed;
    }
}
