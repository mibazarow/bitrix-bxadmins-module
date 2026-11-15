<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Application;
use Mibazarow\Bxadmins\Agent\DaemonAgent;

/**
 * Состояние сервера: диск, память, нагрузка, версии, срок сертификата, cron и демон.
 *
 * Нужен, чтобы агент отвечал на «почему сайт тормозит» фактами, а не догадками.
 * Все данные читаются штатными средствами PHP без root.
 */
class GetServerStatusTool extends AbstractTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'get_server_status';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'get_server_status',
            'description' => 'Состояние сервера: место на диске, память, нагрузка, версии PHP и MySQL, срок SSL-сертификата, работа cron и демона. Для вопросов «почему сайт тормозит» и «всё ли живо».',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'domain' => array('type' => 'string', 'description' => 'Домен для проверки сертификата, по умолчанию сайт админки'),
                ),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $root = (string)$_SERVER['DOCUMENT_ROOT'];

        $status = array(
            'php' => PHP_VERSION,
            'mysql' => self::mysqlVersion(),
            'disk' => self::disk($root),
            'memory' => self::memory(),
            'load' => self::load(),
            'uptime' => self::uptime(),
            'ssl' => self::ssl((string)($args['domain'] ?? '')),
            'cron' => self::cron(),
            'daemon' => self::daemon(),
            'limits' => array(
                'memory_limit' => (string)ini_get('memory_limit'),
                'max_execution_time' => (int)ini_get('max_execution_time'),
                'upload_max_filesize' => (string)ini_get('upload_max_filesize'),
            ),
            'backups' => self::backups(),
        );

        $status['warnings'] = self::warnings($status);

        return $status;
    }

    /** @return array{total:int,free:int,used_percent:float}|null */
    private static function disk(string $root): ?array
    {
        $free = @disk_free_space($root);
        $total = @disk_total_space($root);

        if ($free === false || $total === false || $total <= 0) {
            return null;
        }

        return array(
            'total' => (int)$total,
            'free' => (int)$free,
            'used_percent' => round((1 - $free / $total) * 100, 1),
        );
    }

    /** @return array{limit:string,current:int,peak:int}|null */
    private static function memory(): ?array
    {
        if (!function_exists('memory_get_usage')) {
            return null;
        }

        return array(
            'limit' => (string)ini_get('memory_limit'),
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
        );
    }

    /** @return float[]|null */
    private static function load(): ?array
    {
        if (!function_exists('sys_getloadavg')) {
            return null;
        }

        $load = @sys_getloadavg();

        return is_array($load) ? array_map(static fn ($v): float => round((float)$v, 2), $load) : null;
    }

    private static function uptime(): ?int
    {
        $uptime = @file_get_contents('/proc/uptime');
        if (!is_string($uptime) || $uptime === '') {
            return null;
        }

        $seconds = (int)explode(' ', $uptime)[0];

        return $seconds > 0 ? $seconds : null;
    }

    /** @return array{domain:string,days_left:int|null,valid_to:string|null,error:string|null} */
    private static function ssl(string $domain): array
    {
        $domain = trim($domain);
        if ($domain === '') {
            $domain = (string)\Bitrix\Main\Context::getCurrent()->getRequest()->getHttpHost();
        }

        if ($domain === '') {
            // Сначала сайт харнеса: в CLI и в cron контекст запроса пустой,
            // и таблица сайтов вернула бы домен основного сайта установки.
            $domain = (string)parse_url(\Mibazarow\Bxadmins\Config::daemonSiteUrl(), PHP_URL_HOST);
        }

        if ($domain === '') {
            $row = \Bitrix\Main\SiteTable::getList(array(
                'select' => array('SERVER_NAME'),
                'filter' => array('=ACTIVE' => 'Y'),
                'order' => array('DEF' => 'DESC', 'SORT' => 'ASC'),
                'limit' => 1,
            ))->fetch();
            $domain = (string)($row['SERVER_NAME'] ?? '');
        }

        if ($domain === '') {
            return array('domain' => '', 'days_left' => null, 'valid_to' => null, 'error' => 'домен не определён');
        }

        $context = stream_context_create(array(
            'ssl' => array('capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false),
        ));

        $client = @stream_socket_client(
            'ssl://' . $domain . ':443',
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($client === false) {
            return array(
                'domain' => $domain,
                'days_left' => null,
                'valid_to' => null,
                'error' => 'не удалось подключиться: ' . mb_substr((string)$errstr, 0, 100),
            );
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (!$cert) {
            return array('domain' => $domain, 'days_left' => null, 'valid_to' => null, 'error' => 'сертификат не получен');
        }

        $info = openssl_x509_parse($cert);
        $validTo = (int)($info['validTo_time_t'] ?? 0);

        return array(
            'domain' => $domain,
            'days_left' => $validTo > 0 ? (int)floor(($validTo - time()) / 86400) : null,
            'valid_to' => $validTo > 0 ? date('Y-m-d', $validTo) : null,
            'error' => null,
        );
    }

    /** @return array{last_run:string|null,minutes_ago:int|null} */
    private static function cron(): array
    {
        $last = \CAgent::GetList(array('ID' => 'DESC'), array('!LAST_EXEC' => false))->Fetch();
        $timestamp = $last ? strtotime((string)$last['LAST_EXEC']) : false;

        return array(
            'last_run' => $last ? (string)$last['LAST_EXEC'] : null,
            'minutes_ago' => $timestamp ? (int)floor((time() - $timestamp) / 60) : null,
        );
    }

    /** @return array{running:bool,url:string} */
    private static function daemon(): array
    {
        return array(
            'running' => DaemonAgent::isRunning(),
            'url' => \Mibazarow\Bxadmins\Config::daemonSiteUrl(),
        );
    }

    private static function backups(): array
    {
        try {
            $items = \Mibazarow\Bxadmins\Service\BackupService::list();
        } catch (\Throwable $e) {
            return array('count' => 0, 'newest' => null);
        }

        return array(
            'count' => count($items),
            'newest' => $items !== array() ? (string)$items[0]['file'] : null,
        );
    }

    private static function mysqlVersion(): string
    {
        try {
            return (string)Application::getConnection()->queryScalar('SELECT VERSION()');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** @return string[] что требует внимания */
    private static function warnings(array $status): array
    {
        $warnings = array();

        $freePercent = isset($status['disk']['used_percent']) ? 100 - (float)$status['disk']['used_percent'] : null;
        if ($freePercent !== null && $freePercent < 10) {
            $warnings[] = 'Мало места на диске: свободно ' . round($freePercent) . '%.';
        }

        if (isset($status['load'][0]) && (float)$status['load'][0] > 4) {
            $warnings[] = 'Высокая нагрузка: ' . $status['load'][0] . ' за минуту.';
        }

        if (!empty($status['daemon']['running']) === false) {
            $warnings[] = 'Демон агента не запущен — задачи из чата выполняться не будут.';
        }

        if (($status['cron']['minutes_ago'] ?? 0) > 10) {
            $warnings[] = 'Агенты не запускались ' . ($status['cron']['minutes_ago'] ?? '?') . ' мин — проверьте cron.';
        }

        $days = $status['ssl']['days_left'] ?? null;
        if ($days !== null && $days < 14) {
            $warnings[] = 'SSL-сертификат истекает через ' . $days . ' дн.';
        }

        return $warnings;
    }
}
