<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\SafeHttpClient;

/**
 * Проверка доступности сайта: код ответа и время.
 *
 * Рассчитан на запуск по расписанию («проверяй сайт каждые 10 минут»):
 * при ошибке агент сам напишет в чат задачи, что сайт не отвечает.
 */
class CheckUptimeTool extends AbstractTool
{
    public const DOMAIN = 'core';

    private const TIMEOUT = 10;

    public static function getName(): string
    {
        return 'check_uptime';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'check_uptime',
            'description' => 'Проверяет, что сайт открывается: код ответа, время, размер страницы. Подходит для задачи по расписанию «проверяй сайт каждые 10 минут».',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'url' => array('type' => 'string', 'description' => 'Что проверять, по умолчанию главная сайта'),
                    'expect_text' => array('type' => 'string', 'description' => 'Текст, который должен быть на странице'),
                ),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $url = trim((string)($args['url'] ?? ''));

        if ($url === '') {
            $host = (string)\Bitrix\Main\Context::getCurrent()->getRequest()->getHttpHost();
            if ($host === '') {
                $host = (string)parse_url(\Mibazarow\Bxadmins\Config::daemonSiteUrl(), PHP_URL_HOST);
            }
            $url = 'https://' . ($host !== '' ? $host : 'localhost') . '/';
        }

        $started = microtime(true);
        $response = SafeHttpClient::request($url, array(
            'timeout' => self::TIMEOUT,
            'ua' => 'BXADMIN-S uptime checker',
        ));
        $elapsed = (int)round((microtime(true) - $started) * 1000);

        $body = $response['body'];
        $status = $response['status'];
        $ok = $status >= 200 && $status < 400;

        $result = array(
            'url' => $url,
            'status' => $status,
            'ok' => $ok,
            'time_ms' => $elapsed,
            'size' => is_string($body) ? strlen($body) : 0,
        );

        $expect = trim((string)($args['expect_text'] ?? ''));
        if ($expect !== '') {
            $found = is_string($body) && mb_stripos($body, $expect) !== false;
            $result['expect_text_found'] = $found;

            if (!$found) {
                $result['ok'] = false;
                $result['error'] = 'На странице нет ожидаемого текста «' . mb_substr($expect, 0, 80) . '».';
            }
        }

        if (!$ok) {
            $result['error'] = $status === 0
                ? 'Сайт не отвечает (таймаут ' . self::TIMEOUT . ' с).'
                : 'Сайт вернул HTTP ' . $status . '.';
        }

        return $result;
    }
}
