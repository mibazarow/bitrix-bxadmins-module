<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

/**
 * Помощник для текстов предпросмотра: чат рендерит их как Markdown,
 * поэтому код и диффы нужно оборачивать в забор из обратных кавычек.
 */
final class Markdown
{
    /** Оборачивает код в блок; забор длиннее любого забора внутри кода. */
    public static function code(string $code, string $language = ''): string
    {
        $fence = str_repeat('`', max(3, self::longestFence($code) + 1));

        return $fence . $language . "\n" . rtrim($code) . "\n" . $fence;
    }

    /** Экранирует то, что должно остаться текстом, а не разметкой. */
    public static function text(string $text): string
    {
        return (string)preg_replace('/([\\\\`*_\[\]<>#|~])/', '\\\\$1', $text);
    }

    private static function longestFence(string $code): int
    {
        if (preg_match_all('/`+/', $code, $matches) === false || $matches[0] === array()) {
            return 0;
        }

        return max(array_map('strlen', $matches[0]));
    }
}
