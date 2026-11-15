<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\SkillService;

/** Skill catalog without instructions. */
class ListSkillsTool extends AbstractTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'list_skills';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_skills',
            'description' => 'Lists the skill catalog: name, description, tags, when_to_use, enabled state and usage. Instructions are not included; load the needed skill through use_skill.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'all' => array('type' => 'boolean', 'description' => 'true — include disabled skills.'),
                    'deleted' => array('type' => 'boolean', 'description' => 'true — include skills in the trash.'),
                    'query' => array('type' => 'string', 'description' => 'Optional search over name, description, tags and instructions.'),
                ),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $query = trim((string)($args['query'] ?? ''));

        if ($query !== '') {
            $skills = SkillService::search($query, 50);
            $skills = array_values(array_filter($skills, static function (array $skill) use ($args): bool {
                if (empty($args['deleted']) && $skill['deleted_at'] !== '') {
                    return false;
                }

                return !empty($args['all']) || $skill['enabled'];
            }));
        } else {
            $skills = SkillService::all(empty($args['all']), 200, !empty($args['deleted']));
        }

        $result = array();
        foreach ($skills as $skill) {
            $result[] = array(
                'id' => $skill['id'],
                'name' => $skill['name'],
                'description' => $skill['description'],
                'tags' => $skill['tags'],
                'when_to_use' => $skill['when_to_use'],
                'priority' => $skill['priority'],
                'enabled' => $skill['enabled'],
                'deleted' => $skill['deleted_at'] !== '',
                'use_count' => $skill['use_count'],
                'last_used_at' => $skill['last_used_at'],
                'updated_at' => $skill['updated_at'],
            );
        }

        return array('count' => count($result), 'skills' => $result);
    }
}
