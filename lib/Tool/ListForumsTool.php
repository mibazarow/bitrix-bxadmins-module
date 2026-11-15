<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Forum\ForumTable;
use Bitrix\Main\Loader;

/** Форумы сайта: список с настройками и счётчиками. */
class ListForumsTool extends AbstractTool
{
    public const DOMAIN = 'collaboration';

    public static function getName(): string
    {
        return 'list_forums';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_forums',
            'description' => 'Форумы сайта: ID, название, активность, сортировка, число тем и сообщений. Темы — list_forum_topics.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'active_only' => array('type' => 'boolean', 'description' => 'Только активные форумы'),
                ),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('forum')) {
            return array('error' => 'Модуль forum не установлен. Установите его: install_module с module_id=forum.');
        }

        $filter = array();
        if (!empty($args['active_only'])) {
            $filter['=ACTIVE'] = 'Y';
        }

        $forums = array();

        foreach (ForumTable::getList(array(
            'select' => array('ID', 'NAME', 'DESCRIPTION', 'ACTIVE', 'SORT', 'FORUM_GROUP_ID', 'TOPICS', 'POSTS', 'LAST_POST_DATE'),
            'filter' => $filter,
            'order' => array('SORT' => 'ASC', 'ID' => 'ASC'),
            'limit' => 200,
        ))->fetchAll() as $row) {
            $forums[] = array(
                'id' => (int)$row['ID'],
                'name' => (string)$row['NAME'],
                'description' => mb_substr((string)($row['DESCRIPTION'] ?? ''), 0, 300),
                'active' => (string)$row['ACTIVE'] === 'Y',
                'sort' => (int)$row['SORT'],
                'group_id' => (int)($row['FORUM_GROUP_ID'] ?? 0),
                'topics' => (int)($row['TOPICS'] ?? 0),
                'posts' => (int)($row['POSTS'] ?? 0),
                'last_post' => (string)($row['LAST_POST_DATE'] ?? ''),
            );
        }

        return array('count' => count($forums), 'forums' => $forums);
    }
}
