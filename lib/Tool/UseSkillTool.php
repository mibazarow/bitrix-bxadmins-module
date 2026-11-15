<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\SkillService;

/** Loads a skill's full instructions by name on demand. */
class UseSkillTool extends AbstractTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'use_skill';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'use_skill',
            'description' => 'Loads the full instructions of a skill by its exact name. Call it when the task matches a description in the SKILLS catalog: the prompt contains only names and descriptions, the instructions are returned here. The loaded text stays in the chat history.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'name' => array('type' => 'string', 'description' => 'Exact skill name from the SKILLS catalog, or its numeric ID.'),
                ),
                'required' => array('name'),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $query = trim((string)($args['name'] ?? ''));
        if ($query === '') {
            return array('error' => 'Parameter "name" is required.');
        }

        $skill = SkillService::find($query);
        if ($skill === null) {
            $names = array_column(SkillService::catalog(100), 'name');

            return array(
                'error' => 'Skill not found: ' . $query
                    . ($names !== array() ? '. Available: ' . implode(', ', $names) . '.' : '.'),
            );
        }

        if (!$skill['enabled']) {
            return array('error' => 'Skill "' . $skill['name'] . '" is disabled in settings.');
        }

        SkillService::markUsed($skill['id']);

        return array(
            'name' => $skill['name'],
            'description' => $skill['description'],
            'tags' => $skill['tags'],
            'when_to_use' => $skill['when_to_use'],
            'instructions' => $skill['instructions'],
        );
    }
}
