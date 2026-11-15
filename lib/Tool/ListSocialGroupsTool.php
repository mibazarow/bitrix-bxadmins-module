<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/** Рабочие группы соцсети. */
class ListSocialGroupsTool extends AbstractTool
{
    public const DOMAIN = 'collaboration';

    private const LIMIT = 100;

    public static function getName(): string
    {
        return 'list_social_groups';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_social_groups',
            'description' => 'Рабочие группы соцсети: ID, название, владелец, число участников, тип. Максимум 100.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'search' => array('type' => 'string', 'description' => 'Подстрока названия'),
                ),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,groups:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('socialnetwork')) {
            return array('error' => 'Модуль socialnetwork недоступен.');
        }

        $filter = array('=ACTIVE' => 'Y');
        $search = trim((string)($args['search'] ?? ''));
        if ($search !== '') {
            $filter['%NAME'] = $search;
        }

        $rows = \CSocNetGroup::getList(
            array('ID' => 'DESC'),
            $filter,
            false,
            array('nTopCount' => self::LIMIT + 1),
            array('ID', 'NAME', 'OWNER_ID', 'NUMBER_OF_MEMBERS', 'ACTIVE', 'VISIBLE', 'PROJECT')
        );

        $groups = array();
        $truncated = false;

        while ($row = $rows->fetch()) {
            if (count($groups) >= self::LIMIT) {
                $truncated = true;
                break;
            }

            $groups[] = array(
                'id' => (int)$row['ID'],
                'name' => (string)$row['NAME'],
                'owner_id' => (int)$row['OWNER_ID'],
                'members' => (int)$row['NUMBER_OF_MEMBERS'],
                'visible' => (string)$row['VISIBLE'] === 'Y',
                'project' => (string)$row['PROJECT'] === 'Y',
            );
        }

        return array('count' => count($groups), 'truncated' => $truncated, 'groups' => $groups);
    }
}
