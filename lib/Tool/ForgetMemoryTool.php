<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\MemoryService;

/** Удаляет факт из долговременной памяти по id или заголовку. */
class ForgetMemoryTool extends AbstractTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'forget_memory';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'forget_memory',
            'description' => 'Удаляет факт из долговременной памяти. Вызывай только по прямой просьбе («забудь…»). Принимает ID или точный заголовок.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'query' => array('type' => 'string', 'description' => 'ID записи или её точный заголовок.'),
                ),
                'required' => array('query'),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $deleted = MemoryService::forget((string)($args['query'] ?? ''));

        return array(
            'deleted' => $deleted,
            'note' => $deleted > 0 ? 'Удалено записей: ' . $deleted . '.' : 'Ничего не найдено.',
        );
    }
}
