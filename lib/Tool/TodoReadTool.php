<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\TodoService;

/** Returns the chat's current checklist. */
class TodoReadTool extends AbstractTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'todo_read';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'todo_read',
            'description' => 'Returns the current task checklist for this chat.',
            'parameters' => array(
                'type' => 'object',
                'properties' => new \stdClass(),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $chatId = (int)($context['chat_id'] ?? 0);
        if ($chatId <= 0) {
            return array('error' => 'No chat context for the checklist.');
        }

        $items = TodoService::list($chatId);

        return array('count' => count($items), 'items' => $items);
    }
}
