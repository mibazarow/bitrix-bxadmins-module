<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

/** Поиск исполняемого файла: типовые каталоги плюс PATH. */
final class BinaryFinder
{
    private const DIRS = array('/usr/local/bin', '/usr/bin', '/bin', '/opt/homebrew/bin');

    public static function find(string $name): ?string
    {
        $candidates = array();

        foreach (self::DIRS as $dir) {
            $candidates[] = $dir . '/' . $name;
        }

        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
            if ($dir !== '') {
                $candidates[] = rtrim($dir, '/') . '/' . $name;
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
