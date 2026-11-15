<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Web\HttpClient;

/**
 * Поиск картинок в интернете через Openverse (открытые лицензии, без ключа).
 *
 * Возвращает ссылки на оригинал и превью, автора и лицензию — чтобы агент
 * мог поставить картинку товару и честно назвать источник.
 */
class SearchImagesTool extends AbstractTool
{
    public const DOMAIN = 'content';

    private const ENDPOINT = 'https://api.openverse.org/v1/images/';
    private const COMMONS = 'https://commons.wikimedia.org/w/api.php';
    private const TIMEOUT = 6;
    private const LIMIT_MAX = 10;

    public static function getName(): string
    {
        return 'search_images';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'search_images',
            'description' => 'Ищет картинки в интернете (Openverse, при недоступности — Wikimedia Commons, открытые лицензии) и возвращает ссылки, автора и лицензию. Найденную ссылку можно передать в set_element_images, чтобы поставить картинку товару.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'query' => array('type' => 'string', 'description' => 'Что искать, например «кроссовки белые на белом фоне».'),
                    'limit' => array('type' => 'integer', 'description' => 'Сколько результатов, до 10. По умолчанию 5.'),
                ),
                'required' => array('query'),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            throw new \InvalidArgumentException('Нужен поисковый запрос.');
        }

        $limit = max(1, min(self::LIMIT_MAX, (int)($args['limit'] ?? 5)));

        $client = new HttpClient(array(
            'socketTimeout' => self::TIMEOUT,
            'streamTimeout' => self::TIMEOUT,
            'redirect' => true,
            'redirectMax' => 3,
        ));
        $client->setHeader('User-Agent', 'Mozilla/5.0 (compatible; BXADMIN-S/1.0)');
        $client->setHeader('Accept', 'application/json');

        $body = $client->get(self::ENDPOINT . '?' . http_build_query(array(
            'q' => $query,
            'page_size' => $limit,
            'license_type' => 'all',
            'mature' => 'false',
        )));

        if ($body === false || $client->getStatus() >= 400) {
            // Openverse из некоторых сетей недоступен — переключаемся на свободные
            // картинки Wikimedia Commons, чтобы инструмент не был бесполезен.
            $commons = self::searchCommons($query, $limit);
            if ($commons !== array()) {
                return array(
                    'query' => $query,
                    'count' => count($commons),
                    'images' => $commons,
                    'note' => 'Openverse недоступен; использован Wikimedia Commons (свободные лицензии). Для set_element_images бери thumbnail. Проверь лицензию и укажи автора.',
                );
            }

            return array(
                'error' => 'Поиск картинок недоступен (Openverse HTTP ' . $client->getStatus() . ', Wikimedia — без результата).',
                'query' => $query,
                'images' => array(),
            );
        }

        $decoded = json_decode((string)$body, true);
        $results = is_array($decoded['results'] ?? null) ? $decoded['results'] : array();
        $images = array();

        foreach ($results as $row) {
            $url = trim((string)($row['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $images[] = array(
                'url' => $url,
                'thumbnail' => (string)($row['thumbnail'] ?? ''),
                'title' => trim((string)($row['title'] ?? '')) ?: $query,
                'creator' => (string)($row['creator'] ?? ''),
                'license' => (string)($row['license'] ?? ''),
                'license_url' => (string)($row['license_url'] ?? ''),
                'source' => (string)($row['source'] ?? ''),
                'width' => (int)($row['width'] ?? 0),
                'height' => (int)($row['height'] ?? 0),
            );
        }

        return array(
            'query' => $query,
            'count' => count($images),
            'images' => $images,
            'note' => 'Openverse: открытые лицензии. Для set_element_images бери thumbnail (оригинал может быть огромным TIFF). Проверь лицензию и укажи автора.',
        );
    }

    /** @return array<int,array> */
    private static function searchCommons(string $query, int $limit): array
    {
        $client = new HttpClient(array(
            'socketTimeout' => self::TIMEOUT,
            'streamTimeout' => self::TIMEOUT,
            'redirect' => true,
            'redirectMax' => 3,
        ));
        $client->setHeader('User-Agent', 'Mozilla/5.0 (compatible; BXADMIN-S/1.0)');
        $client->setHeader('Accept', 'application/json');

        $body = $client->get(self::COMMONS . '?' . http_build_query(array(
            'action' => 'query',
            'generator' => 'search',
            'gsrsearch' => 'filetype:bitmap ' . $query,
            'gsrnamespace' => 6,
            'gsrlimit' => $limit,
            'prop' => 'imageinfo',
            'iiprop' => 'url|size|extmetadata',
            'iiurlwidth' => 1200,
            'format' => 'json',
            'formatversion' => 2,
        )));

        if ($body === false || $client->getStatus() >= 400) {
            return array();
        }

        $decoded = json_decode((string)$body, true);
        $pages = is_array($decoded['query']['pages'] ?? null) ? $decoded['query']['pages'] : array();
        $images = array();

        foreach ($pages as $page) {
            $info = $page['imageinfo'][0] ?? null;
            if (!is_array($info) || empty($info['url'])) {
                continue;
            }

            $meta = is_array($info['extmetadata'] ?? null) ? $info['extmetadata'] : array();
            $license = self::metaValue($meta, 'LicenseShortName');
            $artist = trim(strip_tags(self::metaValue($meta, 'Artist')));

            $images[] = array(
                'url' => (string)$info['url'],
                'thumbnail' => (string)($info['thumburl'] ?? ''),
                'title' => (string)($page['title'] ?? $query),
                'creator' => $artist,
                'license' => $license,
                'license_url' => self::metaValue($meta, 'LicenseUrl'),
                'source' => 'Wikimedia Commons',
                'width' => (int)($info['width'] ?? 0),
                'height' => (int)($info['height'] ?? 0),
            );
        }

        return $images;
    }

    private static function metaValue(array $meta, string $key): string
    {
        $value = $meta[$key]['value'] ?? '';

        return is_scalar($value) ? (string)$value : '';
    }
}
