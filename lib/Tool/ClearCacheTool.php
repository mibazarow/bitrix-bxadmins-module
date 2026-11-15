<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

/** Очистка кеша сайта. */
class ClearCacheTool extends AbstractChangeTool
{
    public const DOMAIN = 'security';

    public static function getName(): string
    {
        return 'clear_cache';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'clear_cache',
            'description' => 'Полностью очищает кеш сайта. Кеш восстановится сам при следующих запросах.',
            'parameters' => array(
                'type' => 'object',
                'properties' => new \stdClass(),
            ),
        );
    }

    public static function preview(array $args): array
    {
        return array(
            'summary' => 'Очистка кеша сайта',
            'payload' => array('args' => array()),
            'preview' => 'Кеш будет очищен полностью. Первые запросы после очистки могут быть медленнее.',
        );
    }

    public static function apply(array $payload): array
    {
        // Легаси-исключение: полная очистка кеша — только BXClearCache, D7-аналога нет.
        BXClearCache(true);

        return array('cleared' => true);
    }

    public static function rollback(array $payload): void
    {
        // Кеш восстанавливается сам — откатывать нечего.
    }
}
