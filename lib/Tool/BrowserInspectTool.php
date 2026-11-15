<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\BrowserService;

/**
 * Разбор страницы: заголовок, мета, заголовки, формы, ссылки, картинки,
 * ошибки консоли и неудачные запросы.
 */
class BrowserInspectTool extends AbstractTool
{
    public const DOMAIN = 'design';

    public static function getName(): string
    {
        return 'browser_inspect';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'browser_inspect',
            'description' => 'Открывает страницу в headless-браузере и возвращает DOM-сводку: title, meta, заголовки, формы, ссылки, картинки, ошибки консоли и неудачные запросы.',
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
            return BrowserService::inspect($url);
        } catch (\Throwable $e) {
            return array('error' => $e->getMessage());
        }
    }
}
