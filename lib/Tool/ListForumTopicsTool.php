<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Forum\TopicTable;
use Bitrix\Main\Loader;

/** Темы форума. */
class ListForumTopicsTool extends AbstractTool
{
    public const DOMAIN = 'collaboration';

    private const LIMIT = 100;

    public static function getName(): string
    {
        return 'list_forum_topics';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_forum_topics',
            'description' => 'Темы форума: ID, заголовок, форум, автор, дата, число сообщений. Максимум 100.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'forum_id' => array('type' => 'integer', 'description' => 'ID форума'),
                    'search' => array('type' => 'string', 'description' => 'Подстрока заголовка'),
                ),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,topics:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('forum')) {
            return array('error' => 'Модуль forum недоступен.');
        }

        $filter = array();
        if (($args['forum_id'] ?? 0) > 0) {
            $filter['=FORUM_ID'] = (int)$args['forum_id'];
        }
        if (trim((string)($args['search'] ?? '')) !== '') {
            $filter['%TITLE'] = trim((string)$args['search']);
        }

        $rows = TopicTable::getList(array(
            'select' => array('ID', 'FORUM_ID', 'TITLE', 'USER_START_ID', 'START_DATE', 'POSTS', 'APPROVED'),
            'filter' => $filter,
            'order' => array('ID' => 'DESC'),
            'limit' => self::LIMIT + 1,
        ))->fetchAll();

        $truncated = count($rows) > self::LIMIT;
        $topics = array();

        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            $topics[] = array(
                'id' => (int)$row['ID'],
                'forum_id' => (int)$row['FORUM_ID'],
                'title' => (string)$row['TITLE'],
                'author_id' => (int)$row['USER_START_ID'],
                'date' => $row['START_DATE'] instanceof \Bitrix\Main\Type\Date
                    ? $row['START_DATE']->toString()
                    : (string)$row['START_DATE'],
                'posts' => (int)$row['POSTS'],
                'approved' => (string)$row['APPROVED'] === 'Y',
            );
        }

        return array('count' => count($topics), 'truncated' => $truncated, 'topics' => $topics);
    }
}
