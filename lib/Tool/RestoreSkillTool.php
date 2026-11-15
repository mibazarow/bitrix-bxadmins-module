<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\SkillService;

/** Restores a skill from the trash. */
class RestoreSkillTool extends AbstractTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'restore_skill';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'restore_skill',
            'description' => 'Restores a skill from the trash by ID or exact name. Use only on an explicit user request.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'query' => array('type' => 'string', 'description' => 'Skill ID or exact name.'),
                ),
                'required' => array('query'),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $query = trim((string)($args['query'] ?? ''));
        $restored = SkillService::restore($query);

        return array(
            'restored' => $restored,
            'note' => $restored > 0 ? 'Skill restored from trash.' : 'Not found.',
        );
    }
}
