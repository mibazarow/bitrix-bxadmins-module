<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\SafeHttpClient;

/** SEO-проверка страницы: заголовок, мета, канонический URL. */
class CheckSeoTool extends AbstractTool
{
    public const DOMAIN = 'seo';

    public static function getName(): string
    {
        return 'check_seo';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'check_seo',
            'description' => 'Проверяет SEO страницы сайта: title, description, h1, canonical, robots, длину title и description.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'url' => array('type' => 'string', 'description' => 'Путь или полный URL страницы'),
                ),
                'required' => array('url'),
            ),
        );
    }

    /** @return array<string,mixed>|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $url = trim((string)($args['url'] ?? ''));
        if ($url === '') {
            return array('error' => 'Параметр url обязателен.');
        }

        $url = \Mibazarow\Bxadmins\Service\SiteUrl::absolute($url);
        if ($url === '') {
            return array('error' => 'Не удалось определить домен сайта — передайте полный URL.');
        }

        $response = SafeHttpClient::request($url, array(
            'timeout' => 20,
            'ua' => 'Mozilla/5.0 (compatible; BXADMIN-S/1.0)',
        ));
        $html = $response['body'];
        $status = $response['status'];
        if ($status === 0 || $html === false) {
            return array('error' => 'Не удалось загрузить страницу: ' . $url);
        }

        $pick = static function (string $pattern) use ($html): string {
            if (preg_match($pattern, $html, $m) === 1) {
                return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
            }

            return '';
        };

        $title = $pick('#<title[^>]*>(.*?)</title>#is');
        $description = $pick('#<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']#is');
        if ($description === '') {
            $description = $pick('#<meta[^>]+content=["\'](.*?)["\'][^>]+name=["\']description["\']#is');
        }
        $robots = $pick('#<meta[^>]+name=["\']robots["\'][^>]+content=["\'](.*?)["\']#is');
        $canonical = $pick('#<link[^>]+rel=["\']canonical["\'][^>]+href=["\'](.*?)["\']#is');
        $h1 = $pick('#<h1[^>]*>(.*?)</h1>#is');

        $issues = array();
        if ($title === '') {
            $issues[] = 'Нет тега title.';
        } elseif (mb_strlen($title) > 65) {
            $issues[] = 'Title длиннее 65 символов (' . mb_strlen($title) . ').';
        } elseif (mb_strlen($title) < 20) {
            $issues[] = 'Title короче 20 символов.';
        }
        if ($description === '') {
            $issues[] = 'Нет meta description.';
        } elseif (mb_strlen($description) > 200) {
            $issues[] = 'Description длиннее 200 символов (' . mb_strlen($description) . ').';
        }
        if ($h1 === '') {
            $issues[] = 'Нет H1.';
        }
        if (stripos($robots, 'noindex') !== false) {
            $issues[] = 'Страница закрыта от индексации (noindex).';
        }

        return array(
            'url' => $url,
            'status' => $status,
            'title' => $title,
            'title_length' => mb_strlen($title),
            'description' => $description,
            'description_length' => mb_strlen($description),
            'h1' => $h1,
            'robots' => $robots,
            'canonical' => $canonical,
            'issues' => $issues,
        );
    }
}
