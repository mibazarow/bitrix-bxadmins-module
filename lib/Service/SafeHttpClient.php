<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Uri;

/**
 * HTTP-клиент, который проверяет каждый хоп редиректа через UrlGuard.
 *
 * Обычный HttpClient с redirect=true проверяет только первый URL, поэтому
 * публичная ссылка могла увести запрос на внутренний адрес. Здесь редиректы
 * следуются вручную и каждый Location валидируется.
 */
final class SafeHttpClient
{
    private const MAX_REDIRECTS = 5;

    /**
     * @param array{method?:string,timeout?:int,ua?:string,ports?:int[]|null} $options
     *
     * @return array{status:int, body:string, url:string}
     */
    public static function request(string $url, array $options = array()): array
    {
        $method = strtoupper((string)($options['method'] ?? 'GET'));
        $timeout = (int)($options['timeout'] ?? 20);
        $ports = $options['ports'] ?? null;
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            UrlGuard::assertPublicHttp($current, is_array($ports) ? $ports : null);

            $http = new HttpClient(array(
                'socketTimeout' => $timeout,
                'streamTimeout' => $timeout,
                'redirect' => false,
            ));

            if (!empty($options['ua'])) {
                $http->setHeader('User-Agent', (string)$options['ua']);
            }

            if ($method === 'HEAD') {
                $http->head($current);
                $body = '';
            } else {
                $body = (string)$http->get($current);
            }

            $status = (int)$http->getStatus();

            if ($status >= 300 && $status < 400) {
                $location = (string)$http->getHeaders()->get('Location');
                if ($location !== '') {
                    $current = (string)(new Uri($location))->resolveRelativeUri(new Uri($current));
                    continue;
                }
            }

            return array('status' => $status, 'body' => $body, 'url' => $current);
        }

        throw new \RuntimeException('Слишком много перенаправлений.');
    }
}
