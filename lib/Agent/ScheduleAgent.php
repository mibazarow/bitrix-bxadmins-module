<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Agent;

use Bitrix\Main\Loader;
use Mibazarow\Bxadmins\Service\Logger;
use Mibazarow\Bxadmins\Service\ScheduleService;

/**
 * Планировщик задач по расписанию: раз в минуту проверяет, чему пора
 * запускаться, и ставит запуск в очередь агента.
 *
 * Сам ничего не меняет на сайте — только ставит задачу. Поэтому все правки
 * расписания проходят тот же путь подтверждений и откатов, что и ручные.
 */
class ScheduleAgent
{
    public const CODE = '\\Mibazarow\\Bxadmins\\Agent\\ScheduleAgent::run();';

    /** Раз в минуту: точность расписания — минута. */
    public const INTERVAL = 60;

    public static function run(): string
    {
        try {
            if (!Loader::includeModule('mibazarow.bxadmins')) {
                return '';
            }

            foreach (ScheduleService::due() as $schedule) {
                ScheduleService::run($schedule);
            }
        } catch (\Throwable $e) {
            try {
                Logger::error('ScheduleAgent: ' . $e->getMessage());
            } catch (\Throwable) {
                // Журнал недоступен — планировщик важнее.
            }
        }

        return self::CODE;
    }
}
