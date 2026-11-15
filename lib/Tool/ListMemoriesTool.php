<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\MemoryService;

/** Показывает сохранённые факты долговременной памяти. */
class ListMemoriesTool extends AbstractTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'list_memories';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_memories',
            'description' => 'Показывает всё, что сохранено в долговременной памяти. Обычно память уже есть в контексте; инструмент нужен, если записей много.',
            'parameters' => array('type' => 'object', 'properties' => new \stdClass()),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $items = MemoryService::all(100);

        return array('count' => count($items), 'memories' => $items);
    }
}
