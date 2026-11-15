<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/**
 * Компоненты из local/components и bitrix/components.
 */
class ListComponentsTool extends AbstractTool
{
    public const DOMAIN = 'content';

    private const LIMIT = 500;

    public static function getName(): string
    {
        return 'list_components';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_components',
            'description' => 'Компоненты сайта из local/components и bitrix/components: namespace:name, путь, наличие class.php/component.php, число шаблонов. Код компонентов не выполняется. Максимум 500.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'source' => array(
                        'type' => 'string',
                        'enum' => array('all', 'local', 'bitrix'),
                        'description' => 'Где искать: all (по умолчанию), local или bitrix.',
                    ),
                    'filter' => array(
                        'type' => 'string',
                        'description' => 'Необязательная подстрока namespace:name.',
                    ),
                ),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,components:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $source = strtolower(trim((string)($args['source'] ?? 'all')));
        if (!in_array($source, array('all', 'local', 'bitrix'), true)) {
            return array('error' => 'Некорректный source: допустимо all, local или bitrix.');
        }

        $filter = trim((string)($args['filter'] ?? ''));
        $documentRoot = Loader::getDocumentRoot();
        $components = array();
        $truncated = false;

        foreach (array('local', 'bitrix') as $area) {
            if ($source !== 'all' && $source !== $area) {
                continue;
            }

            if (self::scanArea($documentRoot . '/' . $area . '/components', $area, $filter, $components)) {
                $truncated = true;
                break;
            }
        }

        return array(
            'count' => count($components),
            'truncated' => $truncated,
            'components' => $components,
        );
    }

    /**
     * @param array<int,array<string,mixed>> $components
     * @return bool true, если достигнут лимит
     */
    private static function scanArea(string $root, string $area, string $filter, array &$components): bool
    {
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: array() as $vendorDir) {
            foreach (glob($vendorDir . '/*', GLOB_ONLYDIR) ?: array() as $componentDir) {
                $hasClass = is_file($componentDir . '/class.php');
                $hasComponent = is_file($componentDir . '/component.php');

                if (!$hasClass && !$hasComponent) {
                    continue;
                }

                $name = basename($vendorDir) . ':' . basename($componentDir);
                if ($filter !== '' && stripos($name, $filter) === false) {
                    continue;
                }

                if (count($components) >= self::LIMIT) {
                    return true;
                }

                $templates = 0;
                foreach (glob($componentDir . '/templates/*', GLOB_ONLYDIR) ?: array() as $templateDir) {
                    if (is_dir($templateDir)) {
                        $templates++;
                    }
                }

                $components[] = array(
                    'name' => $name,
                    'path' => '/' . $area . '/components/' . basename($vendorDir) . '/' . basename($componentDir),
                    'source' => $area,
                    'has_class' => $hasClass,
                    'has_component' => $hasComponent,
                    'templates' => $templates,
                );
            }
        }

        return false;
    }
}
