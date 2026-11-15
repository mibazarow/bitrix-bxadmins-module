<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Forum\MessageTable;
use Bitrix\Main\Loader;

/** Сообщения темы форума. */
class ListForumPostsTool extends AbstractTool
{
    public const DOMAIN = 'collaboration';

    private const LIMIT = 100;

    public static function getName(): string
    {
        return 'list_forum_posts';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_forum_posts',
            'description' => 'Сообщения темы форума: ID, автор, дата, текст, признак одобрения. Правка — update_forum_post.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'topic_id' => array('type' => 'integer', 'description' => 'ID темы'),
                    'only_unapproved' => array('type' => 'boolean', 'description' => 'Только скрытые (на модерации)'),
                    'limit' => array('type' => 'integer', 'description' => 'Сколько вернуть, по умолчанию 50'),
                ),
                'required' => array('topic_id'),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $topicId = (int)($args['topic_id'] ?? 0);
        if ($topicId <= 0) {
            return array('error' => 'Параметр topic_id обязателен.');
        }

        if (!Loader::includeModule('forum')) {
            return array('error' => 'Модуль forum не установлен. Установите его: install_module с module_id=forum.');
        }

        $limit = max(1, min(self::LIMIT, (int)($args['limit'] ?? 50)));

        $filter = array('=TOPIC_ID' => $topicId);
        if (!empty($args['only_unapproved'])) {
            $filter['=APPROVED'] = 'N';
        }

        $posts = array();

        foreach (MessageTable::getList(array(
            'select' => array('ID', 'TOPIC_ID', 'FORUM_ID', 'AUTHOR_ID', 'AUTHOR_NAME', 'POST_MESSAGE', 'POST_DATE', 'APPROVED'),
            'filter' => $filter,
            'order' => array('ID' => 'ASC'),
            'limit' => $limit,
        ))->fetchAll() as $row) {
            $posts[] = array(
                'id' => (int)$row['ID'],
                'author_id' => (int)($row['AUTHOR_ID'] ?? 0),
                'author' => (string)($row['AUTHOR_NAME'] ?? ''),
                'date' => (string)($row['POST_DATE'] ?? ''),
                'approved' => (string)$row['APPROVED'] === 'Y',
                'text' => mb_substr((string)$row['POST_MESSAGE'], 0, 2000),
            );
        }

        return array('topic_id' => $topicId, 'count' => count($posts), 'posts' => $posts);
    }
}
