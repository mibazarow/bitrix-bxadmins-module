<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Blog\CommentTable;
use Bitrix\Main\Loader;

/** Удаление комментария блога (модерация). */
class DeleteBlogCommentTool extends AbstractChangeTool
{
    public const DOMAIN = 'collaboration';

    public static function getName(): string
    {
        return 'delete_blog_comment';
    }

    public static function rollbackNeedsRestore(): bool
    {
        return true;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_blog_comment',
            'description' => 'Удаляет комментарий блога. Снимок сохраняется, откат — восстановлением сайта.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'comment_id' => array('type' => 'integer', 'description' => 'ID комментария'),
                ),
                'required' => array('comment_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $commentId = (int)($args['comment_id'] ?? 0);
        if ($commentId <= 0) {
            throw new \InvalidArgumentException('Параметр comment_id обязателен.');
        }

        if (!Loader::includeModule('blog')) {
            throw new \RuntimeException('Модуль blog не установлен.');
        }

        $comment = CommentTable::getList(array(
            'select' => array('ID', 'POST_ID', 'AUTHOR_NAME', 'POST_TEXT'),
            'filter' => array('=ID' => $commentId),
            'limit' => 1,
        ))->fetch();

        if (!$comment) {
            throw new \InvalidArgumentException('Комментарий #' . $commentId . ' не найден.');
        }

        return array(
            'summary' => 'Удаление комментария #' . $commentId,
            'payload' => array('args' => array('comment_id' => $commentId)),
            'preview' => 'Комментарий #' . $commentId . ' (' . $comment['AUTHOR_NAME'] . '): '
                . mb_substr((string)$comment['POST_TEXT'], 0, 200),
        );
    }

    public static function apply(array $payload): array
    {
        $commentId = (int)($payload['args']['comment_id'] ?? 0);

        if (!\CBlogComment::Delete($commentId)) {
            throw new \RuntimeException('Не удалось удалить комментарий #' . $commentId . '.');
        }

        return array('comment_id' => $commentId, 'deleted' => true);
    }

    public static function rollback(array $payload): void
    {
        // Восстанавливается через restore_site.
    }
}
