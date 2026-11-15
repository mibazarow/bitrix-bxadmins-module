<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\BrowserService;

/** Скриншот страницы сайта через headless-браузер. */
class BrowserScreenshotTool extends AbstractTool
{
    public const DOMAIN = 'design';

    public static function getName(): string
    {
        return 'browser_screenshot';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'browser_screenshot',
            'description' => 'Делает скриншот страницы сайта через headless-браузер и возвращает имя файла. Используй, чтобы увидеть, как страница выглядит на самом деле.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'url' => array('type' => 'string', 'description' => 'Путь или полный URL страницы'),
                    'full_page' => array('type' => 'boolean', 'description' => 'Снять всю страницу целиком, а не первый экран'),
                    'width' => array('type' => 'integer', 'description' => 'Ширина окна, по умолчанию 1280'),
                    'height' => array('type' => 'integer', 'description' => 'Высота окна, по умолчанию 900'),
                ),
                'required' => array('url'),
            ),
        );
    }

    /** @return array<string,mixed>|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $url = self::absoluteUrl((string)($args['url'] ?? ''));
        if ($url === '') {
            return array('error' => 'Параметр url обязателен.');
        }

        try {
            $result = BrowserService::screenshot(
                $url,
                (bool)($args['full_page'] ?? false),
                (int)($args['width'] ?? 1280),
                (int)($args['height'] ?? 900)
            );
        } catch (\Throwable $e) {
            return array('error' => $e->getMessage());
        }

        return array(
            'file' => (string)$result['file'],
            'url' => $url,
            'width' => (int)$result['width'],
            'height' => (int)$result['height'],
            'console_errors' => $result['console'] ?? array(),
            'failed_requests' => $result['failed_requests'] ?? array(),
        );
    }

    /** Приводит путь к абсолютному URL сайта. */
    public static function absoluteUrl(string $url): string
    {
        return \Mibazarow\Bxadmins\Service\SiteUrl::absolute($url);
    }
}
