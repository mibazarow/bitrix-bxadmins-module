<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/**
 * Шаблоны сайта из local/templates и bitrix/templates.
 */
class ListTemplatesTool extends AbstractTool
{
    public const DOMAIN = 'design';

    private const LIMIT = 200;
    private const DESCRIPTION_BYTES = 16384;

    public static function getName(): string
    {
        return 'list_templates';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_templates',
            'description' => 'Шаблоны сайта из local/templates и bitrix/templates: ID, путь, наличие header.php/footer.php, название из .description.php (статический разбор, без выполнения кода). Максимум 200.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'source' => array(
                        'type' => 'string',
                        'enum' => array('all', 'local', 'bitrix'),
                        'description' => 'Где искать: all (по умолчанию), local или bitrix.',
                    ),
                ),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,templates:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $source = strtolower(trim((string)($args['source'] ?? 'all')));
        if (!in_array($source, array('all', 'local', 'bitrix'), true)) {
            return array('error' => 'Некорректный source: допустимо all, local или bitrix.');
        }

        $documentRoot = Loader::getDocumentRoot();
        $templates = array();
        $truncated = false;

        foreach (array('local', 'bitrix') as $area) {
            if ($source !== 'all' && $source !== $area) {
                continue;
            }

            foreach (glob($documentRoot . '/' . $area . '/templates/*', GLOB_ONLYDIR) ?: array() as $dir) {
                if (!is_dir($dir)) {
                    continue;
                }

                if (count($templates) >= self::LIMIT) {
                    $truncated = true;
                    break 2;
                }

                $description = self::readDescription($dir);

                $templates[] = array(
                    'id' => basename($dir),
                    'path' => '/' . $area . '/templates/' . basename($dir),
                    'source' => $area,
                    'has_header' => is_file($dir . '/header.php'),
                    'has_footer' => is_file($dir . '/footer.php'),
                    'name' => $description['name'],
                    'description' => $description['description'],
                );
            }
        }

        return array(
            'count' => count($templates),
            'truncated' => $truncated,
            'templates' => $templates,
        );
    }

    /**
     * Статически читает .description.php (include не выполняем — чужой код не запускаем).
     *
     * @return array{name:string,description:string}
     */
    private static function readDescription(string $dir): array
    {
        $file = '';
        foreach (array($dir . '/.description.php', $dir . '/description.php') as $candidate) {
            if (is_file($candidate)) {
                $file = $candidate;
                break;
            }
        }

        if ($file === '') {
            return array('name' => '', 'description' => '');
        }

        $raw = (string)@file_get_contents($file, false, null, 0, self::DESCRIPTION_BYTES);

        return array(
            'name' => self::extract($raw, 'NAME'),
            'description' => self::extract($raw, 'DESCRIPTION'),
        );
    }

    private static function extract(string $raw, string $key): string
    {
        if (!preg_match('/["\']' . $key . '["\']\s*=>\s*["\']([^"\']{1,255})["\']/u', $raw, $matches)) {
            return '';
        }

        return trim($matches[1]);
    }
}
