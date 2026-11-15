<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

/**
 * Удаление файла из /upload (чистка мусора).
 *
 * Удаляется только файл внутри /upload; ссылки на него в контенте не проверяются —
 * предпросмотр честно предупреждает об этом.
 */
class DeleteMediaFileTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'delete_media_file';
    }

    public static function needsSqlDump(): bool
    {
        // Файл возвращается из снимка, дамп базы не нужен.
        return false;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_media_file',
            'description' => 'Удаляет файл из /upload. Файл сохраняется в снимке, откат вернёт его. Ссылки на файл в контенте не проверяются.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'path' => array('type' => 'string', 'description' => 'Путь от корня сайта: /upload/iblock/ab/file.jpg'),
                ),
                'required' => array('path'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $relative = self::normalize((string)($args['path'] ?? ''));

        if ($relative === null) {
            throw new \InvalidArgumentException('Путь должен быть внутри /upload, например /upload/iblock/ab/file.jpg.');
        }

        $absolute = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . $relative;

        if (!is_file($absolute)) {
            throw new \InvalidArgumentException('Файл не найден: ' . $relative);
        }

        $size = (int)@filesize($absolute);

        return array(
            'summary' => 'Удаление файла ' . $relative,
            'payload' => array(
                'args' => array('path' => $relative),
                'before' => array('content' => base64_encode((string)file_get_contents($absolute))),
            ),
            'preview' => 'Файл ' . $relative . ' (' . self::humanSize($size) . ') будет удалён. '
                . 'Если он используется на страницах, картинка пропадёт — проверьте перед подтверждением.',
        );
    }

    public static function apply(array $payload): array
    {
        $relative = (string)($payload['args']['path'] ?? '');
        $absolute = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . $relative;

        if (!@unlink($absolute)) {
            throw new \RuntimeException('Не удалось удалить файл ' . $relative . '.');
        }

        return array('path' => $relative, 'deleted' => true);
    }

    public static function rollback(array $payload): void
    {
        $relative = (string)($payload['args']['path'] ?? '');
        $content = base64_decode((string)($payload['before']['content'] ?? ''), true);

        if ($relative === '' || $content === false) {
            return;
        }

        $absolute = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . $relative;
        @mkdir(dirname($absolute), 0755, true);
        @file_put_contents($absolute, $content);
    }

    private static function normalize(string $path): ?string
    {
        $path = '/' . ltrim(str_replace('\\', '/', trim($path)), '/');

        if (!str_starts_with($path, '/upload/') || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' МБ';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' КБ';
        }

        return $bytes . ' Б';
    }
}
