<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\FileSnapshotService;
use Mibazarow\Bxadmins\Service\LocalFileGuard;

/** Удаление файла сайта со снапшотом для отката. */
class DeleteSiteFileTool extends AbstractChangeTool
{
    public const DOMAIN = 'design';

    public static function getName(): string
    {
        return 'delete_site_file';
    }

    public static function needsSqlDump(): bool
    {
        // Файл восстанавливается git-точкой; база не меняется.
        return false;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_site_file',
            'description' => 'Удаляет файл сайта (кроме ядра, upload и local/php_interface). Снапшот сохраняется, откат восстановит файл.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'path' => array('type' => 'string', 'description' => 'Путь от корня сайта'),
                ),
                'required' => array('path'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $path = LocalFileGuard::assertSitePath((string)($args['path'] ?? ''));
        $absolute = LocalFileGuard::absolute($path);

        if (!is_file($absolute)) {
            throw new \InvalidArgumentException('Файл не найден: ' . $path);
        }

        return array(
            'summary' => 'Удаление файла ' . $path,
            'payload' => array(
                'path' => $path,
                'args' => array('path' => $path),
                'before' => array('size' => (int)filesize($absolute)),
            ),
            'preview' => 'Будет удалён ' . $path . ' (' . (int)filesize($absolute) . ' байт). Снапшот сохранится.',
        );
    }

    public static function apply(array $payload): array
    {
        $path = LocalFileGuard::assertSitePath((string)($payload['path'] ?? ''));
        $absolute = LocalFileGuard::absolute($path);

        $snapshot = FileSnapshotService::snapshot($path, (int)($payload['change_id'] ?? 0));

        if (!@unlink($absolute)) {
            throw new \RuntimeException('Не удалось удалить файл.');
        }

        return array('path' => $path, 'deleted' => true, 'snapshot' => $snapshot);
    }

    public static function rollback(array $payload): void
    {
        FileSnapshotService::restore($payload);
    }
}
