<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

/**
 * Медиатека: что лежит в /upload.
 *
 * Нужна, чтобы ответить «какие картинки есть», «что занимает место» и «удали мусор»,
 * не заходя в файловый менеджер.
 */
class ListMediaFilesTool extends AbstractTool
{
    public const DOMAIN = 'content';

    private const MAX_FILES = 300;
    private const MAX_DEPTH = 4;

    public static function getName(): string
    {
        return 'list_media_files';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_media_files',
            'description' => 'Файлы в /upload: путь, размер, дата. Можно фильтровать по папке, расширению и искать самые большие. Удаление — delete_media_file.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'path' => array('type' => 'string', 'description' => 'Подкаталог внутри /upload, например iblock или bxadmins'),
                    'extension' => array('type' => 'string', 'description' => 'Только это расширение: jpg, png, pdf'),
                    'sort' => array('type' => 'string', 'description' => 'date (свежие), size (крупные), name'),
                    'limit' => array('type' => 'integer', 'description' => 'Сколько вернуть, по умолчанию 50'),
                ),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $root = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . '/upload';

        if (!is_dir($root)) {
            return array('error' => 'Каталог /upload не найден.');
        }

        $path = self::safePath((string)($args['path'] ?? ''));
        $base = $path === '' ? $root : $root . '/' . $path;

        if (!is_dir($base)) {
            return array('error' => 'Каталог /upload/' . $path . ' не найден.');
        }

        $extension = strtolower(trim((string)($args['extension'] ?? '')));
        $limit = max(1, min(self::MAX_FILES, (int)($args['limit'] ?? 50)));
        $sort = strtolower(trim((string)($args['sort'] ?? 'date')));

        $files = array();
        $totalBytes = 0;

        foreach (self::walk($base, $root, self::MAX_DEPTH) as $file) {
            $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));

            if ($extension !== '' && $ext !== $extension) {
                continue;
            }

            $size = (int)@filesize($file);
            $totalBytes += $size;

            $files[] = array(
                'path' => '/' . ltrim(substr($file, strlen(rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/'))), '/'),
                'size' => $size,
                'modified' => date('Y-m-d H:i', (int)@filemtime($file)),
                'extension' => $ext,
                'mtime' => (int)@filemtime($file),
            );
        }

        usort($files, static function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'size' => $b['size'] <=> $a['size'],
                'name' => strcmp($a['path'], $b['path']),
                default => $b['mtime'] <=> $a['mtime'],
            };
        });

        $total = count($files);
        $files = array_slice($files, 0, $limit);

        foreach ($files as &$file) {
            unset($file['mtime']);
        }
        unset($file);

        return array(
            'path' => '/upload/' . $path,
            'total_files' => $total,
            'total_size' => $totalBytes,
            'returned' => count($files),
            'files' => $files,
        );
    }

    /** Обход с ограничением глубины: медиатека бывает огромной. */
    private static function walk(string $dir, string $root, int $depth): \Generator
    {
        if ($depth < 0) {
            return;
        }

        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                yield from self::walk($path, $root, $depth - 1);
                continue;
            }

            if (is_file($path)) {
                yield $path;
            }
        }
    }

    /** Путь только внутри /upload: без «..» и абсолютных путей. */
    private static function safePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        if ($path === '' || str_contains($path, '..')) {
            return '';
        }

        return (string)preg_replace('#[^A-Za-z0-9._/-]#', '', $path);
    }
}
