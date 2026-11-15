<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

/**
 * Приведение текста к валидному UTF-8. Соединение и таблицы модуля — utf8mb4,
 * поэтому 4-байтовые символы (эмодзи) сохраняются как есть.
 */
final class Utf8Helper
{
    public static function sanitize(string $text): string
    {
        if ($text === '' || preg_match('//u', $text)) {
            return $text;
        }

        // Невалидный UTF-8 MySQL отклонит целиком — приводим к валидному.
        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
}
