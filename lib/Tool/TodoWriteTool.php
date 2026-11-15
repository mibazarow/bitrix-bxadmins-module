<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\TodoService;

/** Replaces the chat's task checklist. */
class TodoWriteTool extends AbstractTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'todo_write';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'todo_write',
            'description' => 'Replaces the task checklist for the current chat. Use it for multi-step work: mark items pending, in_progress, done or cancelled. The checklist is shown to you in the system prompt on later steps.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'items' => array(
                        'type' => 'array',
                        'description' => 'Full checklist; replaces the previous one.',
                        'items' => array(
                            'type' => 'object',
                            'properties' => array(
                                'text' => array('type' => 'string', 'description' => 'Checklist item.'),
                                'status' => array(
                                    'type' => 'string',
                                    'enum' => array('pending', 'in_progress', 'done', 'cancelled'),
                                    'description' => 'Item state, default pending.',
                                ),
                            ),
                            'required' => array('text'),
                        ),
                    ),
                ),
                'required' => array('items'),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $chatId = (int)($context['chat_id'] ?? 0);
        if ($chatId <= 0) {
            return array('error' => 'No chat context for the checklist.');
        }

        $items = is_array($args['items'] ?? null) ? $args['items'] : array();
        $list = TodoService::replace($chatId, (int)($context['user_id'] ?? 0), $items);

        return array('updated' => true, 'count' => count($list), 'items' => $list);
    }
}
