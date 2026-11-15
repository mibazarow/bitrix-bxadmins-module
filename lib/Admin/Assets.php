<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Admin;

use Bitrix\Main\Application;

/** URL статики админки с cache-busting по времени изменения файла. */
final class Assets
{
    private const BASE = '/local/modules/mibazarow.bxadmins/admin/assets/';

    /** URL файла из admin/assets/ с версией по mtime. */
    public static function url(string $file): string
    {
        $path = Application::getDocumentRoot() . self::BASE . $file;

        return self::BASE . $file . '?v=' . (is_file($path) ? (int)filemtime($path) : 0);
    }
}
