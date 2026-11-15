<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\FileSnapshotService;
use Mibazarow\Bxadmins\Service\LocalFileGuard;

/** Удаление файла из /local/ со снапшотом для отката. */
class DeleteLocalFileTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'delete_local_file';
    }

    public static function needsSqlDump(): bool
    {
        // Файл восстанавливается git-точкой; база не меняется.
        return false;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_local_file',
            'description' => 'Удаляет файл внутри /local/ (кроме php_interface, самого модуля и чужих модулей в local/modules). Снапшот сохраняется, откат восстановит файл.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'path' => array('type' => 'string', 'description' => 'Путь вида local/templates/site/old.php'),
                ),
                'required' => array('path'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $relativePath = LocalFileGuard::assertWritableLocalPath((string)($args['path'] ?? ''));
        $absolute = LocalFileGuard::absolute($relativePath);

        if (!is_file($absolute)) {
            throw new \InvalidArgumentException('Файл не найден: ' . $relativePath);
        }

        return array(
            'summary' => 'Удаление файла ' . $relativePath,
            'payload' => array(
                'path' => $relativePath,
                'args' => array('path' => $relativePath),
                'before' => array('size' => (int)filesize($absolute)),
            ),
            'preview' => 'Будет удалён файл ' . $relativePath . ' (' . (int)filesize($absolute) . ' байт).'
                . ' Снапшот сохранится — откат восстановит файл.',
        );
    }

    public static function apply(array $payload): array
    {
        $relativePath = LocalFileGuard::assertWritableLocalPath((string)($payload['path'] ?? ''));
        $absolute = LocalFileGuard::absolute($relativePath);
        $changeId = (int)($payload['change_id'] ?? 0);

        $snapshot = FileSnapshotService::snapshot($relativePath, $changeId);

        if (!@unlink($absolute)) {
            throw new \RuntimeException('Не удалось удалить файл.');
        }

        return array('path' => $relativePath, 'deleted' => true, 'snapshot' => $snapshot);
    }

    public static function rollback(array $payload): void
    {
        FileSnapshotService::restore($payload);
    }
}
