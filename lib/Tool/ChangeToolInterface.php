<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

/**
 * Инструмент, меняющий данные или файлы: сначала preview, затем применение или подтверждение пользователем.
 */
interface ChangeToolInterface extends ToolInterface
{
    /** @return array{summary:string,payload:array,preview:string} */
    public static function preview(array $args): array;

    /** @param array $payload ['args'=>array, 'before'=>array] */
    public static function apply(array $payload): array;

    public static function rollback(array $payload): void;

    /** Нужен ли SQL-дамп перед выполнением: данные — да, только файлы — нет. */
    public static function needsSqlDump(): bool;

    /**
     * Точечный откат невозможен: изменение возвращается восстановлением сайта
     * к точке отката (файлы + база), а не обработчиком rollback().
     */
    public static function rollbackNeedsRestore(): bool;
}
