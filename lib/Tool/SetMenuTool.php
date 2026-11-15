<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\FileSnapshotService;
use Mibazarow\Bxadmins\Service\LocalFileGuard;

/**
 * Меню сайта. Меню Битрикса — PHP-файл вида .top.menu.php с массивом $aMenuLinks.
 * Инструмент создаёт или заменяет такой файл целиком.
 */
class SetMenuTool extends AbstractChangeTool
{
    public const DOMAIN = 'design';

    private const TYPES = array(
        'top' => '.top.menu.php',
        'left' => '.left.menu.php',
        'bottom' => '.bottom.menu.php',
    );

    public static function getName(): string
    {
        return 'set_menu';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'set_menu',
            'description' => 'Создаёт или заменяет меню сайта (.top/.left/.bottom.menu.php). Пункты: [{"text": "О нас", "link": "/about/"}].',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'path' => array('type' => 'string', 'description' => 'Каталог меню, например / или /catalog/. По умолчанию /'),
                    'type' => array('type' => 'string', 'description' => 'Тип меню: top (по умолчанию), left, bottom'),
                    'items' => array(
                        'type' => 'array',
                        'description' => 'Пункты меню',
                        'items' => array(
                            'type' => 'object',
                            'properties' => array(
                                'text' => array('type' => 'string'),
                                'link' => array('type' => 'string'),
                            ),
                        ),
                    ),
                    'site_dir' => array('type' => 'string', 'description' => 'Каталог сайта, если он не в корне'),
                ),
                'required' => array('items'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $type = strtolower(trim((string)($args['type'] ?? 'top'))) ?: 'top';

        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('Тип меню: ' . implode(', ', array_keys(self::TYPES)) . '.');
        }

        $items = is_array($args['items'] ?? null) ? $args['items'] : array();
        if ($items === array()) {
            throw new \InvalidArgumentException('Нужен хотя бы один пункт меню.');
        }

        $normalized = array();
        foreach ($items as $item) {
            $text = trim((string)($item['text'] ?? ''));
            $link = trim((string)($item['link'] ?? ''));

            if ($text === '' || $link === '') {
                throw new \InvalidArgumentException('У каждого пункта нужны text и link.');
            }

            $normalized[] = array('text' => $text, 'link' => $link);
        }

        $dir = trim((string)($args['path'] ?? '/'));
        $dir = '/' . trim($dir, '/');
        if ($dir !== '/') {
            $dir .= '/';
        }

        $siteDir = trim((string)($args['site_dir'] ?? ''));
        $relative = ltrim($siteDir . $dir . self::TYPES[$type], '/');
        $relative = preg_replace('#/+#', '/', $relative) ?? $relative;

        $path = LocalFileGuard::assertSitePath($relative);
        $absolute = LocalFileGuard::absolute($path);
        $exists = is_file($absolute);

        $lines = array();
        foreach ($normalized as $item) {
            $lines[] = '— ' . $item['text'] . ' → ' . $item['link'];
        }

        return array(
            'summary' => ($exists ? 'Изменение' : 'Создание') . ' меню ' . $type . ' (' . $dir . ')',
            'payload' => array(
                'path' => $path,
                'args' => array('path' => $path, 'items' => $normalized),
                'before' => array('exists' => $exists, 'hash' => $exists ? md5((string)file_get_contents($absolute)) : ''),
            ),
            'preview' => 'Меню ' . $type . ' в ' . $dir . ' (' . count($normalized) . ' пунктов):' . "\n" . implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $path = LocalFileGuard::assertSitePath((string)($payload['path'] ?? ''));
        $items = (array)($payload['args']['items'] ?? array());
        $absolute = LocalFileGuard::absolute($path);
        $before = (array)($payload['before'] ?? array());

        if (!empty($before['exists']) && is_file($absolute) && md5((string)file_get_contents($absolute)) !== (string)$before['hash']) {
            throw new \RuntimeException('Файл меню изменился после предпросмотра — повторите.');
        }

        $snapshot = FileSnapshotService::snapshot($path, (int)($payload['change_id'] ?? 0));

        $lines = array("<?php", "", "// Меню создано агентом BXADMIN-S.", "\$aMenuLinks = array(");
        foreach ($items as $item) {
            $lines[] = '    array(' . self::export((string)$item['text']) . ', ' . self::export((string)$item['link']) . ', array(), array(), ""),';
        }
        $lines[] = ");";

        $dir = dirname($absolute);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог ' . dirname($path) . '.');
        }

        if (@file_put_contents($absolute, implode("\n", $lines) . "\n") === false) {
            throw new \RuntimeException('Не удалось записать меню ' . $path . '.');
        }

        @chmod($absolute, 0644);

        return array('path' => $path, 'items' => count($items), 'snapshot' => $snapshot);
    }

    public static function rollback(array $payload): void
    {
        FileSnapshotService::restore($payload);
    }

    private static function export(string $value): string
    {
        return "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), $value) . "'";
    }
}
