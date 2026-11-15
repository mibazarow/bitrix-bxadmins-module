<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Agent;

use Bitrix\Main\Application;
use Mibazarow\Bxadmins\Config;
use Mibazarow\Bxadmins\Service\Logger;

/**
 * Сторож Node-демона: раз в минуту проверяет, жив ли процесс, и поднимает его.
 * Нужен потому, что systemd-юнит требует root, а cron Битрикса уже работает
 * под пользователем сайта — этого достаточно.
 */
class DaemonAgent
{
    public const CODE = '\\Mibazarow\\Bxadmins\\Agent\\DaemonAgent::run();';

    public const INTERVAL = 60;

    private const SCRIPT = '/local/modules/mibazarow.bxadmins/daemon/bxadmins-daemon.js';

    public static function run(): string
    {
        try {
            if (!\Bitrix\Main\Loader::includeModule('mibazarow.bxadmins')) {
                return '';
            }

            if (!self::isRunning()) {
                self::start();
            }
        } catch (\Throwable $e) {
            try {
                Logger::error('DaemonAgent: ' . $e->getMessage());
            } catch (\Throwable) {
                // Журнал недоступен — сторож важнее.
            }
        }

        return self::CODE;
    }

    /**
     * Демон уже работает. Ищем именно процесс node с нашим скриптом: строка вида
     * «node .../bxadmins-daemon.js». Так проверка не срабатывает на собственный
     * командный файл, где встречается имя скрипта.
     */
    public static function isRunning(): bool
    {
        $output = array();
        @exec('ps -eo args 2>/dev/null', $output);

        foreach ($output as $line) {
            if (!str_contains($line, 'bxadmins-daemon.js') || !str_contains($line, 'node')) {
                continue;
            }

            // Командные оболочки и сам grep содержат имя скрипта в аргументах — это не демон.
            if (str_contains($line, 'bash -c') || str_contains($line, 'sh -c') || str_contains($line, 'grep')) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Запускает демон отдельной сессией. Секрет передаётся через окружение
     * (не через командную строку), чтобы не светиться в ps.
     */
    private static function start(): void
    {
        $root = Application::getDocumentRoot();
        $script = $root . self::SCRIPT;

        if (!is_file($script)) {
            Logger::error('DaemonAgent: не найден ' . $script);

            return;
        }

        $node = trim((string)@shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            Logger::error('DaemonAgent: node не найден в PATH');

            return;
        }

        $env = getenv();
        if (!is_array($env)) {
            $env = array();
        }

        $env['SECRET'] = Config::workerSecret();
        $env['SITE_URL'] = self::siteUrl($root);
        $env['NODE_ENV'] = 'production';

        $descriptors = array(
            0 => array('file', '/dev/null', 'r'),
            1 => array('file', self::logFile(), 'a'),
            2 => array('file', self::logFile(), 'a'),
        );

        // setsid -f форкается и сразу выходит: демон остаётся в новой сессии и переживает
        // завершение запроса, а proc_close() не ждёт его конца.
        $process = @proc_open(
            array('setsid', '-f', $node, $script),
            $descriptors,
            $pipes,
            $root,
            $env
        );

        if (!is_resource($process)) {
            Logger::error('DaemonAgent: не удалось запустить демон');

            return;
        }

        // Не ждём завершения: процесс должен жить дальше сам.
        proc_close($process);
    }

    private static function siteUrl(string $root): string
    {
        // Демон всегда работает с сайтом админки: cron общий для установки,
        // и SiteUrl::current() из чужого docroot дал бы другой домен.
        $url = Config::daemonSiteUrl();

        return $url !== '' ? $url : \Mibazarow\Bxadmins\Service\SiteUrl::current();
    }

    private static function logFile(): string
    {
        $dir = Config::logDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir . '/daemon.log';
    }
}
