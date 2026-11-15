<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Agent;

use Bitrix\Main\Loader;
use Mibazarow\Bxadmins\Service\Logger;
use Mibazarow\Bxadmins\Service\MigrationService;
use Mibazarow\Bxadmins\Service\RetentionService;

/**
 * Суточный агент обслуживания: чистит устаревшие данные, чтобы база модуля
 * не раздувалась. Работает по cron вместе с остальными агентами Битрикса.
 */
class MaintenanceAgent
{
    public const CODE = '\\Mibazarow\\Bxadmins\\Agent\\MaintenanceAgent::run();';

    /** Раз в сутки. */
    public const INTERVAL = 86400;

    public static function run(): string
    {
        try {
            if (!Loader::includeModule('mibazarow.bxadmins')) {
                return '';
            }

            // Миграции схемы — до чистки: новый код должен увидеть новые колонки.
            try {
                MigrationService::run();
            } catch (\Throwable $e) {
                Logger::error('MigrationService: ' . $e->getMessage());
            }

            // По ТЗ в журнал пишутся только ошибки; статистика чистки не логируется.
            RetentionService::run();
        } catch (\Throwable $e) {
            try {
                Logger::error('MaintenanceAgent: ' . $e->getMessage());
            } catch (\Throwable) {
                // Журнал недоступен — агент важнее.
            }
        }

        return self::CODE;
    }
}
