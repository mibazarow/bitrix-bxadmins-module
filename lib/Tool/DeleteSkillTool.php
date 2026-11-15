<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Mibazarow\Bxadmins\Service\SkillService;

/** Moves a skill to the trash, or purges it permanently. */
class DeleteSkillTool extends AbstractTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'delete_skill';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_skill',
            'description' => 'Deletes a skill by ID or exact name. By default the skill goes to the trash and can be restored; pass purge=true only when the user explicitly asks for permanent deletion.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'query' => array('type' => 'string', 'description' => 'Skill ID or exact name.'),
                    'purge' => array('type' => 'boolean', 'description' => 'true — delete permanently, including history.'),
                ),
                'required' => array('query'),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $query = trim((string)($args['query'] ?? ''));
        $purge = !empty($args['purge']);
        $deleted = SkillService::remove($query, $purge);

        return array(
            'deleted' => $deleted,
            'purged' => $purge && $deleted > 0,
            'note' => $deleted > 0
                ? ($purge ? 'Deleted permanently.' : 'Moved to trash; can be restored.')
                : 'Not found.',
        );
    }
}
