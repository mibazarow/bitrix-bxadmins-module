<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Blog\CommentTable;
use Bitrix\Main\Loader;

/** Комментарии поста блога. */
class ListBlogCommentsTool extends AbstractTool
{
    public const DOMAIN = 'collaboration';

    public static function getName(): string
    {
        return 'list_blog_comments';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_blog_comments',
            'description' => 'Комментарии поста блога: ID, автор, дата, текст, опубликован ли.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'post_id' => array('type' => 'integer', 'description' => 'ID поста'),
                    'limit' => array('type' => 'integer', 'description' => 'Сколько вернуть, по умолчанию 50'),
                ),
                'required' => array('post_id'),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $postId = (int)($args['post_id'] ?? 0);
        if ($postId <= 0) {
            return array('error' => 'Параметр post_id обязателен.');
        }

        if (!Loader::includeModule('blog')) {
            return array('error' => 'Модуль blog не установлен. Установите его: install_module с module_id=blog.');
        }

        $limit = max(1, min(200, (int)($args['limit'] ?? 50)));
        $comments = array();

        foreach (CommentTable::getList(array(
            'select' => array('ID', 'POST_ID', 'AUTHOR_ID', 'AUTHOR_NAME', 'POST_TEXT', 'DATE_CREATE', 'PUBLISH_STATUS'),
            'filter' => array('=POST_ID' => $postId),
            'order' => array('ID' => 'ASC'),
            'limit' => $limit,
        ))->fetchAll() as $row) {
            $comments[] = array(
                'id' => (int)$row['ID'],
                'author' => (string)($row['AUTHOR_NAME'] ?? ''),
                'author_id' => (int)($row['AUTHOR_ID'] ?? 0),
                'date' => (string)($row['DATE_CREATE'] ?? ''),
                'published' => (string)$row['PUBLISH_STATUS'] === 'P',
                'text' => mb_substr((string)$row['POST_TEXT'], 0, 1500),
            );
        }

        return array('post_id' => $postId, 'count' => count($comments), 'comments' => $comments);
    }
}
