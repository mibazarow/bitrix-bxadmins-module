<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\SkillService;

/** Creates or updates a skill by name. Only on explicit request. */
class SaveSkillTool extends AbstractTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'save_skill';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'save_skill',
            'description' => 'Creates or updates a skill — a named instruction the agent follows on matching tasks. Call only on an explicit user request to create or change a skill.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'name' => array('type' => 'string', 'description' => 'Short unique name, e.g. "SEO copy for products".'),
                    'description' => array('type' => 'string', 'description' => 'When to apply the skill (max 500 chars).'),
                    'instructions' => array('type' => 'string', 'description' => 'The rules themselves: steps, structure, requirements (max 8000 chars).'),
                    'enabled' => array('type' => 'boolean', 'description' => 'Whether the skill is enabled. Default true.'),
                    'tags' => array('type' => 'string', 'description' => 'Optional comma-separated tags for discovery.'),
                    'when_to_use' => array('type' => 'string', 'description' => 'Optional extra trigger description.'),
                    'priority' => array('type' => 'integer', 'description' => 'Catalog order, lower first (0–1000, default 100).'),
                ),
                'required' => array('name', 'instructions'),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $name = trim((string)($args['name'] ?? ''));
        $existing = SkillService::find($name, true);

        $id = SkillService::save(
            $name,
            (string)($args['description'] ?? ''),
            (string)($args['instructions'] ?? ''),
            !isset($args['enabled']) || (bool)$args['enabled'],
            array(
                'tags' => (string)($args['tags'] ?? ''),
                'when_to_use' => (string)($args['when_to_use'] ?? ''),
                'priority' => isset($args['priority']) ? (int)$args['priority'] : 100,
                'created_by' => (int)($context['user_id'] ?? 0),
                'source' => 'chat',
                'chat_id' => (int)($context['chat_id'] ?? 0),
            )
        );

        return array(
            'saved' => true,
            'created' => $existing === null,
            'id' => $id,
            'name' => $name,
            'note' => 'The prompt holds only the catalog; call use_skill to load the instructions when needed.',
        );
    }
}
