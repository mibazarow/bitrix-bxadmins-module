<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\LocalFileGuard;

/**
 * Чтение файла сайта, кода ядра или стороннего модуля.
 * Секреты, дампы базы и пользовательские файлы закрыты.
 */
class ReadFileTool extends AbstractTool
{
    public const DOMAIN = 'content';

    private const MAX_BYTES = 200000;

    public static function getName(): string
    {
        return 'read_file';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'read_file',
            'description' => 'Читает файл сайта или исходники модуля — включая /bitrix/modules и /local/modules (чтобы понять API). Путь от корня сайта, например local/templates/site/header.php или bitrix/modules/iblock/lib/elementtable.php. Закрыты: .settings.php, dbconn.php, .env, upload/, дампы и ключи. Максимум 200 КБ.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'path' => array('type' => 'string', 'description' => 'Путь от корня сайта'),
                ),
                'required' => array('path'),
            ),
        );
    }

    /** @return array{path:string,size:int,content:string}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $relativePath = (string)($args['path'] ?? '');

        try {
            $relativePath = LocalFileGuard::assertReadablePath($relativePath);
        } catch (\Throwable $e) {
            return array('error' => $e->getMessage());
        }

        $absolute = LocalFileGuard::absolute($relativePath);

        if (!is_file($absolute)) {
            return array('error' => 'Файл не найден: ' . $relativePath);
        }

        $size = (int)filesize($absolute);
        if ($size > self::MAX_BYTES) {
            return array('error' => 'Файл больше 200 КБ — читать целиком нельзя.');
        }

        $content = @file_get_contents($absolute);
        if ($content === false) {
            return array('error' => 'Не удалось прочитать файл.');
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            return array('error' => 'Файл не является текстом в UTF-8 — читать его через агента нельзя.');
        }

        return array('path' => $relativePath, 'size' => $size, 'content' => $content);
    }
}
