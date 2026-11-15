<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\SkillService;

/** Searches skills by name, description, tags or instructions. */
class SearchSkillsTool extends AbstractTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'search_skills';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'search_skills',
            'description' => 'Searches the skill catalog by name, description, tags, when_to_use or instructions. Returns catalog entries without instruction bodies; load a matching skill with use_skill.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'query' => array('type' => 'string', 'description' => 'Search phrase.'),
                    'limit' => array('type' => 'integer', 'description' => 'Maximum results, 1–100 (default 20).'),
                ),
                'required' => array('query'),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') {
            return array('error' => 'Parameter "query" is required.');
        }

        $limit = isset($args['limit']) ? (int)$args['limit'] : 20;
        $skills = SkillService::search($query, max(1, min(100, $limit)));

        return array('query' => $query, 'count' => count($skills), 'skills' => $skills);
    }
}
