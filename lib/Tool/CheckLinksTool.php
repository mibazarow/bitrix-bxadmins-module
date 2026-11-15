<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\SafeHttpClient;
use Mibazarow\Bxadmins\Service\UrlGuard;

/**
 * Обход сайта и поиск битых ссылок.
 *
 * Начинает с указанной страницы, идёт по внутренним ссылкам на ограниченную
 * глубину и проверяет каждую HEAD/GET-запросом. Внешние ссылки не обходятся —
 * только проверяются те, что найдены на страницах.
 */
class CheckLinksTool extends AbstractTool
{
    public const DOMAIN = 'seo';

    // Простая подпись проходит защиту сайта; «полный» Chrome-UA с серверного IP режется 503.
    private const UA = 'Mozilla/5.0 (compatible; BXADMIN-S/1.0)';

    private const MAX_PAGES = 40;
    private const MAX_LINKS = 600;
    private const TIMEOUT = 10;

    public static function getName(): string
    {
        return 'check_links';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'check_links',
            'description' => 'Обходит страницы сайта и находит битые ссылки (404, 5xx, таймауты). Проверяет до 40 страниц и 600 ссылок. Для внешних ссылок — только те, что найдены на страницах.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'start_url' => array('type' => 'string', 'description' => 'С какой страницы начать, по умолчанию главная'),
                    'max_pages' => array('type' => 'integer', 'description' => 'Сколько страниц обойти, до 40'),
                    'check_external' => array('type' => 'boolean', 'description' => 'Проверять внешние ссылки (медленнее)'),
                ),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $base = self::baseUrl();
        $start = self::absolute((string)($args['start_url'] ?? '/'), $base);
        $maxPages = max(1, min(self::MAX_PAGES, (int)($args['max_pages'] ?? 20)));
        $checkExternal = !empty($args['check_external']);

        $queue = array($start);
        $visited = array();
        $checked = array();
        $broken = array();
        $pages = 0;

        while ($queue !== array() && $pages < $maxPages && count($checked) < self::MAX_LINKS) {
            $url = array_shift($queue);
            $key = self::normalize($url);

            if (isset($visited[$key])) {
                continue;
            }

            $visited[$key] = true;

            // Ссылки на внутренние адреса не проверяем: это SSRF, а не польза.
            if (!UrlGuard::isPublicHttp($url, null)) {
                continue;
            }

            $page = self::fetch($url);
            $pages++;
            usleep(250000);

            if ($page === null) {
                $broken[] = array('url' => $url, 'status' => 0, 'reason' => 'не отвечает');
                continue;
            }

            if ($page['status'] >= 400) {
                $broken[] = array('url' => $url, 'status' => $page['status'], 'reason' => 'HTTP ' . $page['status']);
            }

            foreach (self::links($page['body'], $url, $base) as $link) {
                if (count($checked) >= self::MAX_LINKS) {
                    break;
                }

                $isInternal = str_starts_with($link, $base);

                if (!$isInternal && !$checkExternal) {
                    continue;
                }

                $linkKey = self::normalize($link);
                if (isset($checked[$linkKey])) {
                    continue;
                }

                $checked[$linkKey] = true;

                if (!UrlGuard::isPublicHttp($link, null)) {
                    continue;
                }

                if ($isInternal && self::isPage($link)) {
                    $queue[] = $link;
                }

                $status = self::status($link);
                if ($status >= 400 || $status === 0) {
                    $broken[] = array(
                        'url' => $link,
                        'status' => $status,
                        'reason' => $status === 0 ? 'не отвечает' : 'HTTP ' . $status,
                        'found_on' => $url,
                    );
                }
            }
        }

        return array(
            'start_url' => $start,
            'pages_checked' => $pages,
            'links_checked' => count($checked),
            'broken_count' => count($broken),
            'broken' => array_slice($broken, 0, 50),
            'truncated' => count($broken) > 50 || $pages >= $maxPages,
        );
    }

    private static function baseUrl(): string
    {
        $host = (string)\Bitrix\Main\Context::getCurrent()->getRequest()->getHttpHost();

        if ($host === '') {
            $host = (string)parse_url(\Mibazarow\Bxadmins\Config::daemonSiteUrl(), PHP_URL_HOST);
        }

        if ($host === '') {
            $row = \Bitrix\Main\SiteTable::getList(array(
                'select' => array('SERVER_NAME'),
                'filter' => array('=ACTIVE' => 'Y'),
                'order' => array('DEF' => 'DESC', 'SORT' => 'ASC'),
                'limit' => 1,
            ))->fetch();
            $host = (string)($row['SERVER_NAME'] ?? 'localhost');
        }

        return 'https://' . $host;
    }

    private static function absolute(string $url, string $base): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return $base . '/' . ltrim($url, '/');
    }

    private static function normalize(string $url): string
    {
        $url = (string)preg_replace('/#.*$/', '', $url);

        return rtrim($url, '/') ?: '/';
    }

    /** Страница ли это (а не картинка/файл): обходить имеет смысл только HTML. */
    private static function isPage(string $url): bool
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));

        return $extension === '' || in_array($extension, array('php', 'html', 'htm'), true);
    }

    /** @return array{status:int,body:string}|null */
    private static function fetch(string $url): ?array
    {
        try {
            $response = SafeHttpClient::request($url, array(
                'timeout' => self::TIMEOUT,
                'ua' => self::UA,
            ));
        } catch (\Throwable $e) {
            return null;
        }

        if ($response['body'] === '' && $response['status'] === 0) {
            return null;
        }

        return array('status' => $response['status'], 'body' => $response['body']);
    }

    private static function status(string $url): int
    {
        $status = self::head($url);

        // Защита сайта ограничивает частоту: 429/503 — повод подождать и повторить,
        // иначе быстрый обход выглядит как «сотни битых ссылок».
        if ($status === 429 || $status === 503) {
            usleep(1500000);
            $status = self::head($url);
        }

        usleep(250000);

        return $status;
    }

    private static function head(string $url): int
    {
        try {
            $response = SafeHttpClient::request($url, array(
                'method' => 'HEAD',
                'timeout' => self::TIMEOUT,
                'ua' => self::UA,
            ));
        } catch (\Throwable $e) {
            return 0;
        }

        return $response['status'];
    }

    /** @return string[] абсолютные ссылки со страницы */
    private static function links(string $html, string $pageUrl, string $base): array
    {
        $links = array();

        if (preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\']/i', $html, $matches) === false) {
            return $links;
        }

        $host = (string)parse_url($pageUrl, PHP_URL_HOST);
        $scheme = (string)parse_url($pageUrl, PHP_URL_SCHEME) ?: 'https';

        foreach ($matches[1] as $href) {
            $href = html_entity_decode(trim($href), ENT_QUOTES, 'UTF-8');

            if ($href === '' || str_starts_with($href, '#')
                || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')
                || str_starts_with($href, 'javascript:')) {
                continue;
            }

            if (str_starts_with($href, '//')) {
                $links[] = $scheme . ':' . $href;
                continue;
            }

            if (str_starts_with($href, '/')) {
                $links[] = $base . $href;
                continue;
            }

            if (preg_match('#^https?://#i', $href) === 1) {
                $links[] = $href;
                continue;
            }

            // Относительная ссылка — склеиваем с текущей страницей.
            $dir = rtrim(dirname((string)parse_url($pageUrl, PHP_URL_PATH)), '/');
            $links[] = $scheme . '://' . $host . $dir . '/' . $href;
        }

        return array_values(array_unique($links));
    }
}
