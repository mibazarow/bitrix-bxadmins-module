<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\SafeHttpClient;
use Mibazarow\Bxadmins\Service\SiteUrl;

/**
 * Массовая проверка SEO по URL из sitemap или явному списку. Только чтение.
 */
class BulkCheckSeoTool extends AbstractTool
{
    public const DOMAIN = 'seo';

    private const MAX_URLS = 20;
    private const MAX_CHILD_SITEMAPS = 6;

    public static function getName(): string
    {
        return 'bulk_check_seo';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'bulk_check_seo',
            'description' => 'Проверяет SEO у нескольких страниц сразу: title, description, H1, robots, canonical. URL берёт из sitemap.xml (или из urls). Максимум 20 страниц за вызов. Только чтение.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'urls' => array(
                        'type' => 'array',
                        'items' => array('type' => 'string'),
                        'description' => 'Явный список URL; если не задан — берутся страницы из sitemap.xml',
                    ),
                    'limit' => array('type' => 'integer', 'description' => 'Сколько страниц проверить, по умолчанию 10, максимум 20'),
                ),
            ),
        );
    }

    /** @return array<string,mixed>|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $limit = max(1, min(self::MAX_URLS, (int)($args['limit'] ?? 10)));

        $urls = array();
        if (isset($args['urls']) && is_array($args['urls']) && $args['urls'] !== array()) {
            foreach ($args['urls'] as $url) {
                $url = trim((string)$url);
                if ($url !== '') {
                    $urls[] = SiteUrl::absolute($url);
                }
            }
        } else {
            $collected = self::urlsFromSitemap();
            if ($collected['error'] !== null) {
                return array('error' => $collected['error']);
            }
            $urls = $collected['urls'];
        }

        $urls = array_values(array_unique(array_filter($urls)));
        if ($urls === array()) {
            return array('error' => 'Не удалось получить ни одного URL: sitemap пуст или не содержит страниц. Передайте urls вручную.');
        }

        $urls = array_slice($urls, 0, $limit);
        $items = array();
        $withIssues = 0;
        $failures = 0;

        foreach ($urls as $url) {
            $result = CheckSeoTool::execute(array('url' => $url), $context);

            if (isset($result['error'])) {
                $failures++;
                $items[] = array('url' => $url, 'error' => (string)$result['error']);
                continue;
            }

            $issues = (array)($result['issues'] ?? array());
            if ($issues !== array()) {
                $withIssues++;
            }

            $items[] = array(
                'url' => (string)($result['url'] ?? $url),
                'status' => (int)($result['status'] ?? 0),
                'title_length' => (int)($result['title_length'] ?? 0),
                'description_length' => (int)($result['description_length'] ?? 0),
                'h1' => (string)($result['h1'] ?? ''),
                'issues' => $issues,
            );
        }

        return array(
            'checked' => count($items),
            'with_issues' => $withIssues,
            'failures' => $failures,
            'limit' => $limit,
            'items' => $items,
        );
    }

    /** @return array{urls:string[],error:?string} */
    private static function urlsFromSitemap(): array
    {
        $base = SiteUrl::current();
        if ($base === '') {
            return array('urls' => array(), 'error' => 'Не удалось определить домен сайта.');
        }

        $index = self::fetch($base . '/sitemap.xml');
        if ($index['body'] === '') {
            return array('urls' => array(), 'error' => 'sitemap.xml недоступен: ' . $index['error']);
        }

        $locs = self::locs($index['body']);
        $pages = array();
        $children = 0;

        foreach ($locs as $loc) {
            if (preg_match('/\.xml($|\?)/i', $loc) === 1) {
                if ($children >= self::MAX_CHILD_SITEMAPS) {
                    break;
                }

                $children++;
                $child = self::fetch($loc);
                if ($child['body'] !== '') {
                    $pages = array_merge($pages, self::locs($child['body']));
                }

                continue;
            }

            $pages[] = $loc;
        }

        return array('urls' => $pages, 'error' => null);
    }

    /** @return array{body:string,error:string} */
    private static function fetch(string $url): array
    {
        try {
            $response = SafeHttpClient::request($url, array(
                'timeout' => 15,
                'ua' => 'Mozilla/5.0 (compatible; BXADMIN-S/1.0)',
            ));

            if ((int)$response['status'] === 0) {
                return array('body' => '', 'error' => 'HTTP 0');
            }

            return array('body' => (string)$response['body'], 'error' => '');
        } catch (\Throwable $e) {
            return array('body' => '', 'error' => $e->getMessage());
        }
    }

    /** @return string[] */
    private static function locs(string $xml): array
    {
        if (preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $xml, $matches) !== false) {
            return array_map('html_entity_decode', $matches[1]);
        }

        return array();
    }
}
