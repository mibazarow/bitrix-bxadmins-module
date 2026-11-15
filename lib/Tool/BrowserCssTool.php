<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\BrowserService;

/**
 * Вычисленные CSS-стили элементов. Именно это позволяет проверить результат
 * правки дизайна: цвет кнопки, размер шрифта, отступы — фактические, после CSS.
 */
class BrowserCssTool extends AbstractTool
{
    public const DOMAIN = 'design';

    public static function getName(): string
    {
        return 'browser_css';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'browser_css',
            'description' => 'Возвращает вычисленные CSS-стили элементов по селектору: цвет, фон, шрифт, отступы, размеры. Так проверяется, что правка дизайна применилась.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'url' => array('type' => 'string', 'description' => 'Путь или полный URL страницы'),
                    'selector' => array('type' => 'string', 'description' => 'CSS-селектор, например .btn или header h1'),
                    'properties' => array(
                        'type' => 'array',
                        'items' => array('type' => 'string'),
                        'description' => 'Список CSS-свойств. Если не указан — набор по умолчанию (цвет, фон, шрифт, отступы).',
                    ),
                ),
                'required' => array('url', 'selector'),
            ),
        );
    }

    /** @return array<string,mixed>|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $url = BrowserScreenshotTool::absoluteUrl((string)($args['url'] ?? ''));
        $selector = trim((string)($args['selector'] ?? ''));

        if ($url === '' || $selector === '') {
            return array('error' => 'Нужны url и selector.');
        }

        $properties = is_array($args['properties'] ?? null) ? $args['properties'] : array();

        try {
            return BrowserService::computedCss($url, $selector, $properties);
        } catch (\Throwable $e) {
            return array('error' => $e->getMessage());
        }
    }
}
