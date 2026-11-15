<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\BrowserService;

/** Дымовой тест страницы: открывается, не пустая, без ошибок консоли и битых картинок. */
class BrowserSmokeTool extends AbstractTool
{
    public const DOMAIN = 'design';

    public static function getName(): string
    {
        return 'browser_smoke';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'browser_smoke',
            'description' => 'Проверяет страницу после изменений: открывается, есть текст, нет ошибок в консоли, нет битых картинок и неудачных запросов.',
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
        $url = BrowserScreenshotTool::absoluteUrl((string)($args['url'] ?? ''));
        if ($url === '') {
            return array('error' => 'Параметр url обязателен.');
        }

        try {
            return BrowserService::smoke($url);
        } catch (\Throwable $e) {
            return array('error' => $e->getMessage());
        }
    }
}
