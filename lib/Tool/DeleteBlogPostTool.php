<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Blog\PostTable;
use Bitrix\Main\Loader;

/** Удаление поста блога. */
class DeleteBlogPostTool extends AbstractChangeTool
{
    public const DOMAIN = 'collaboration';

    public static function getName(): string
    {
        return 'delete_blog_post';
    }

    public static function rollbackNeedsRestore(): bool
    {
        // Удаление уносит комментарии и связи: точечно не вернуть.
        return true;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_blog_post',
            'description' => 'Удаляет пост блога вместе с комментариями. Откат — восстановлением сайта.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'post_id' => array('type' => 'integer', 'description' => 'ID поста'),
                ),
                'required' => array('post_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $postId = (int)($args['post_id'] ?? 0);
        if ($postId <= 0) {
            throw new \InvalidArgumentException('Параметр post_id обязателен.');
        }

        if (!Loader::includeModule('blog')) {
            throw new \RuntimeException('Модуль blog не установлен.');
        }

        $post = PostTable::getList(array(
            'select' => array('ID', 'TITLE', 'NUM_COMMENTS'),
            'filter' => array('=ID' => $postId),
            'limit' => 1,
        ))->fetch();

        if (!$post) {
            throw new \InvalidArgumentException('Пост #' . $postId . ' не найден.');
        }

        return array(
            'summary' => 'Удаление поста «' . $post['TITLE'] . '»',
            'payload' => array('args' => array('post_id' => $postId)),
            'preview' => 'Пост «' . $post['TITLE'] . '» будет удалён вместе с '
                . (int)($post['NUM_COMMENTS'] ?? 0) . ' комментариями.',
        );
    }

    public static function apply(array $payload): array
    {
        $postId = (int)($payload['args']['post_id'] ?? 0);

        if (!\CBlogPost::Delete($postId)) {
            throw new \RuntimeException('Не удалось удалить пост #' . $postId . '.');
        }

        return array('post_id' => $postId, 'deleted' => true);
    }

    public static function rollback(array $payload): void
    {
        // Восстанавливается через restore_site.
    }
}
