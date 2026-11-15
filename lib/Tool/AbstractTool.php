<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

/**
 * Базовая обвязка инструмента: домен прав задаётся константой DOMAIN в наследнике.
 */
abstract class AbstractTool implements ToolInterface
{
    public const DOMAIN = '';

    public static function getDomain(): string
    {
        return static::DOMAIN;
    }
}
