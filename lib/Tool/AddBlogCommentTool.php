<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Blog\PostTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

/** Комментарий к посту блога. */
class AddBlogCommentTool extends AbstractChangeTool
{
    public const DOMAIN = 'collaboration';

    public static function getName(): string
    {
        return 'add_blog_comment';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'add_blog_comment',
            'description' => 'Добавляет комментарий к посту блога от имени текущего пользователя.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'post_id' => array('type' => 'integer', 'description' => 'ID поста'),
                    'text' => array('type' => 'string', 'description' => 'Текст комментария'),
                ),
                'required' => array('post_id', 'text'),
            ),
        );
    }

    public static function preview(array $args, array $context = array()): array
    {
        $postId = (int)($args['post_id'] ?? 0);
        $text = trim((string)($args['text'] ?? ''));

        if ($postId <= 0 || $text === '') {
            throw new \InvalidArgumentException('Нужны post_id и text.');
        }

        if (!Loader::includeModule('blog')) {
            throw new \RuntimeException('Модуль blog не установлен.');
        }

        $post = PostTable::getList(array(
            'select' => array('ID', 'TITLE', 'BLOG_ID'),
            'filter' => array('=ID' => $postId),
            'limit' => 1,
        ))->fetch();

        if (!$post) {
            throw new \InvalidArgumentException('Пост #' . $postId . ' не найден.');
        }

        $userId = (int)($context['user_id'] ?? 0) ?: (int)\Bitrix\Main\Engine\CurrentUser::get()->getId();

        return array(
            'summary' => 'Комментарий к посту «' . $post['TITLE'] . '»',
            'payload' => array(
                'args' => array(
                    'post_id' => $postId,
                    'blog_id' => (int)$post['BLOG_ID'],
                    'text' => mb_substr($text, 0, 5000),
                    'author_id' => $userId,
                ),
            ),
            'preview' => 'Комментарий к посту «' . $post['TITLE'] . "»:\n\n" . mb_substr($text, 0, 1000),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());

        $result = \CBlogComment::Add(array(
            'POST_ID' => (int)$args['post_id'],
            'BLOG_ID' => (int)$args['blog_id'],
            'AUTHOR_ID' => (int)$args['author_id'],
            'POST_TEXT' => (string)$args['text'],
            'DATE_CREATE' => new DateTime(),
            'PUBLISH_STATUS' => 'P',
        ));

        if (!$result) {
            throw new \RuntimeException('Не удалось добавить комментарий.');
        }

        return array('comment_id' => (int)$result, 'post_id' => (int)$args['post_id']);
    }

    public static function rollback(array $payload): void
    {
        $commentId = (int)($payload['result']['comment_id'] ?? 0);
        if ($commentId > 0) {
            \CBlogComment::Delete($commentId);
        }
    }
}
