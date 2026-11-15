<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

/** Содержимое robots.txt. */
class GetRobotsTool extends AbstractTool
{
    public const DOMAIN = 'seo';

    public static function getName(): string
    {
        return 'get_robots';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'get_robots',
            'description' => 'Показывает robots.txt сайта. Менять — set_robots.',
            'parameters' => array('type' => 'object', 'properties' => new \stdClass()),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $path = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . '/robots.txt';

        return array(
            'exists' => is_file($path),
            'content' => is_file($path) ? (string)file_get_contents($path) : '',
        );
    }
}
