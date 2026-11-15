<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\IO\Directory;

/**
 * Загрузка файла (картинки, документа) в upload/.
 *
 * Содержимое передаётся в base64: модель не умеет бинарные аргументы, а base64
 * безопасно проходит через JSON. Путь возвращается публичный — его можно
 * подставить в свойство элемента или в HTML.
 */
class UploadFileTool extends AbstractChangeTool
{
    public const DOMAIN = 'design';

    private const MAX_BYTES = 8 * 1024 * 1024;

    /** Разрешённые расширения: изображения и документы, без исполняемых файлов. */
    private const EXTENSIONS = array(
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'ico',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip',
    );

    public static function getName(): string
    {
        return 'upload_file';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'upload_file',
            'description' => 'Сохраняет файл в upload/bxadmins/ и возвращает публичный путь. Содержимое — base64. Расширения: jpg, png, gif, webp, ico, pdf, doc(x), xls(x), csv, txt, zip. До 8 МБ.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'name' => array('type' => 'string', 'description' => 'Имя файла с расширением: logo.png'),
                    'content_base64' => array('type' => 'string', 'description' => 'Содержимое файла в base64'),
                ),
                'required' => array('name', 'content_base64'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $name = self::normalizeName((string)($args['name'] ?? ''));
        $binary = self::decode((string)($args['content_base64'] ?? ''));

        $relative = self::relativePath($name);
        $absolute = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . $relative;
        $exists = is_file($absolute);

        return array(
            'summary' => 'Файл ' . $relative,
            'payload' => array(
                'args' => array('path' => $relative, 'content' => base64_encode($binary)),
                'before' => array(
                    'exists' => $exists,
                    'content' => $exists ? base64_encode((string)file_get_contents($absolute)) : '',
                ),
            ),
            'preview' => ($exists ? 'Замена файла ' : 'Новый файл ') . $relative
                . ' (' . self::humanSize(strlen($binary)) . ').',
        );
    }

    public static function apply(array $payload): array
    {
        $relative = (string)($payload['args']['path'] ?? '');
        $binary = base64_decode((string)($payload['args']['content'] ?? ''), true);

        if ($binary === false || $relative === '') {
            throw new \InvalidArgumentException('Нет данных файла.');
        }

        $absolute = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . $relative;

        Directory::createDirectory(dirname($absolute));

        if (@file_put_contents($absolute, $binary) === false) {
            throw new \RuntimeException('Не удалось записать файл ' . $relative . '.');
        }

        return array(
            'path' => $relative,
            'url' => $relative,
            'bytes' => strlen($binary),
        );
    }

    public static function rollback(array $payload): void
    {
        $relative = (string)($payload['args']['path'] ?? '');
        $before = (array)($payload['before'] ?? array());

        if ($relative === '') {
            return;
        }

        $absolute = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . $relative;

        if (empty($before['exists'])) {
            @unlink($absolute);

            return;
        }

        $binary = base64_decode((string)($before['content'] ?? ''), true);
        if ($binary !== false) {
            @file_put_contents($absolute, $binary);
        }
    }

    private static function normalizeName(string $name): string
    {
        $name = basename(trim($name));
        $name = (string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);

        if ($name === '' || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException('Некорректное имя файла.');
        }

        $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new \InvalidArgumentException(
                'Расширение .' . $extension . ' не разрешено. Допустимы: ' . implode(', ', self::EXTENSIONS)
            );
        }

        return $name;
    }

    private static function decode(string $base64): string
    {
        $base64 = trim($base64);
        // Допускаем data-URL: «data:image/png;base64,....».
        if (str_contains($base64, 'base64,')) {
            $base64 = substr($base64, (int)strpos($base64, 'base64,') + 7);
        }

        $binary = base64_decode($base64, true);
        if ($binary === false || $binary === '') {
            throw new \InvalidArgumentException('Содержимое не является корректным base64.');
        }

        if (strlen($binary) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Файл больше 8 МБ.');
        }

        return $binary;
    }

    private static function relativePath(string $name): string
    {
        // Уникальный подкаталог по дате: не засоряем upload/ и не перетираем чужие файлы.
        return '/upload/bxadmins/' . date('Y-m') . '/' . $name;
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
