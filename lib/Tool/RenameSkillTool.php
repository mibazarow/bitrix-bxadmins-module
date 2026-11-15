<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\SkillService;

/** Renames a skill without creating a duplicate. */
class RenameSkillTool extends AbstractTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'rename_skill';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'rename_skill',
            'description' => 'Renames an existing skill. Use only on an explicit user request.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'query' => array('type' => 'string', 'description' => 'Current skill ID or exact name.'),
                    'name' => array('type' => 'string', 'description' => 'New unique name.'),
                ),
                'required' => array('query', 'name'),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $skill = SkillService::find((string)($args['query'] ?? ''), true);
        if (!$skill) {
            return array('error' => 'Skill not found.');
        }

        SkillService::rename($skill['id'], (string)($args['name'] ?? ''));

        return array('renamed' => true, 'id' => $skill['id'], 'name' => (string)($args['name'] ?? ''));
    }
}
