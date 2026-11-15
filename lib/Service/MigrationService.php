<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Web\Json;
use Mibazarow\Bxadmins\Config;

/**
 * Версионированные миграции схемы: install/migrations/NNN_*.php.
 *
 * Файл миграции — обычный PHP-скрипт, который получает соединение через
 * Application::getConnection(). Выполненные файлы запоминаются в опции
 * schema_migrations, поэтому повторный запуск безопасен.
 */
final class MigrationService
{
    public const OPTION = 'schema_migrations';

    /** @return string[] выполненные в этом запуске файлы */
    public static function run(): array
    {
        $dir = dirname(__DIR__, 2) . '/install/migrations';
        $files = glob($dir . '/*.php') ?: array();
        sort($files, SORT_STRING);

        if ($files === array()) {
            return array();
        }

        $done = self::done();
        $executed = array();

        foreach ($files as $file) {
            $name = basename($file);

            if (isset($done[$name])) {
                continue;
            }

            $result = include $file;

            if ($result === false) {
                throw new \RuntimeException('Миграция ' . $name . ' вернула false.');
            }

            $done[$name] = date('c');
            $executed[] = $name;
        }

        if ($executed !== array()) {
            Config::setMany(array(self::OPTION => Json::encode($done)));
        }

        return $executed;
    }

    /** @return array<string,string> */
    private static function done(): array
    {
        $raw = trim(Config::get(self::OPTION));

        if ($raw === '') {
            return array();
        }

        try {
            $decoded = Json::decode($raw);
        } catch (\Throwable $e) {
            return array();
        }

        return is_array($decoded) ? $decoded : array();
    }
}
