<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Agent;

use Mibazarow\Bxadmins\Config;
use Mibazarow\Bxadmins\Model\McpServerTable;
use Mibazarow\Bxadmins\Service\Logger;

/**
 * Сторож Scrapling MCP: поднимает streamable-http сервер скрапера, если он упал.
 * Сервер нужен агенту как MCP-инструменты (доступны через mcp_tool).
 */
class ScraperAgent
{
    public const CODE = '\\Mibazarow\\Bxadmins\\Agent\\ScraperAgent::run();';
    public const INTERVAL = 60;

    public static function run(): string
    {
        try {
            if (!\Bitrix\Main\Loader::includeModule('mibazarow.bxadmins')) {
                return '';
            }

            $server = self::server();
            if ($server === null) {
                return self::CODE;
            }

            $binary = self::binary();
            if ($binary === '' || !is_file($binary)) {
                return self::CODE;
            }

            if (!self::isRunning($binary)) {
                self::start($binary, $server);
            }
        } catch (\Throwable $e) {
            try {
                Logger::error('ScraperAgent: ' . $e->getMessage());
            } catch (\Throwable) {
            }
        }

        return self::CODE;
    }

    /** @return array{port:int,token:string}|null */
    private static function server(): ?array
    {
        try {
            $row = McpServerTable::getList(array(
                'select' => array('URL', 'TOKEN', 'ACTIVE'),
                'filter' => array('%URL' => 'scrapling', '=ACTIVE' => 'Y'),
                'limit' => 1,
            ))->fetch();
            if (!$row) {
                $row = McpServerTable::getList(array(
                    'select' => array('URL', 'TOKEN', 'ACTIVE'),
                    'filter' => array('%URL' => '3101', '=ACTIVE' => 'Y'),
                    'limit' => 1,
                ))->fetch();
            }
        } catch (\Throwable $e) {
            return null;
        }

        if (!$row) {
            return null;
        }

        $url = (string)$row['URL'];
        $port = (int)(parse_url($url, PHP_URL_PORT) ?: 3101);

        return array('port' => $port, 'token' => (string)$row['TOKEN']);
    }

    private static function binary(): string
    {
        $custom = trim((string)\Bitrix\Main\Config\Option::get('mibazarow.bxadmins', 'scraper_bin', ''));
        if ($custom !== '') {
            return $custom;
        }

        return dirname(Config::logDir()) . '/scrapling/.venv/bin/scrapling-mcp';
    }

    private static function isRunning(string $binary): bool
    {
        $output = array();
        @exec('ps -eo args 2>/dev/null', $output);

        foreach ($output as $line) {
            if (!str_contains($line, 'scrapling-mcp') || !str_contains($line, '--http')) {
                continue;
            }
            if (str_contains($line, 'bash -c') || str_contains($line, 'grep')) {
                continue;
            }

            return true;
        }

        return false;
    }

    /** @param array{port:int,token:string} $server */
    private static function start(string $binary, array $server): void
    {
        $log = Config::logDir() . '/scrapling-mcp.log';
        $etdir = dirname(Config::logDir());
        $env = getenv();
        if (!is_array($env)) {
            $env = array();
        }
        // Токен — через окружение, чтобы не светиться в списке процессов.
        if ($server['token'] !== '') {
            $env['SCRAPLING_MCP_AUTH_TOKEN'] = $server['token'];
        }

        $args = array('setsid', '-f', $binary, '--http', '--host', '127.0.0.1', '--port', (string)(int)$server['port']);
        $descriptors = array(
            0 => array('file', '/dev/null', 'r'),
            1 => array('file', $log, 'a'),
            2 => array('file', $log, 'a'),
        );

        $process = @proc_open($args, $descriptors, $pipes, $etdir, $env);
        if (!is_resource($process)) {
            Logger::error('ScraperAgent: не удалось запустить Scrapling MCP');

            return;
        }

        proc_close($process);
        Logger::info('ScraperAgent: запущен Scrapling MCP на порту ' . (int)$server['port']);
    }
}
