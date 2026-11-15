<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\MemoryService;

/**
 * Сохраняет факт в долговременную память. Вызывается только по прямой просьбе.
 */
class SaveMemoryTool extends AbstractTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'save_memory';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'save_memory',
            'description' => 'Сохраняет факт в долговременную память, которая переживает удаление чатов. Вызывай ТОЛЬКО когда пользователь явно просит запомнить («запомни…»). Ничего в память сам не записывай.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'title' => array('type' => 'string', 'description' => 'Короткое название факта, например «Пароль от админки». По умолчанию — начало текста.'),
                    'content' => array('type' => 'string', 'description' => 'Что именно запомнить, дословно.'),
                ),
                'required' => array('content'),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $userId = (int)($context['user_id'] ?? 0);

        $id = MemoryService::save(
            (string)($args['title'] ?? ''),
            (string)($args['content'] ?? ''),
            $userId
        );

        return array(
            'saved' => true,
            'id' => $id,
            'title' => (string)($args['title'] ?? ''),
            'note' => 'Запомнил. Это сохранится и после удаления чата.',
        );
    }
}
