<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Forum\ForumTable;
use Bitrix\Main\Loader;

/** Удаление форума вместе с темами и сообщениями. */
class DeleteForumTool extends AbstractChangeTool
{
    public const DOMAIN = 'collaboration';

    public static function getName(): string
    {
        return 'delete_forum';
    }

    public static function rollbackNeedsRestore(): bool
    {
        // Форум удаляется вместе с темами и сообщениями: точечно не вернуть.
        return true;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_forum',
            'description' => 'Удаляет форум со всеми темами и сообщениями. Откат — восстановлением сайта.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'forum_id' => array('type' => 'integer', 'description' => 'ID форума'),
                ),
                'required' => array('forum_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $forumId = (int)($args['forum_id'] ?? 0);
        if ($forumId <= 0) {
            throw new \InvalidArgumentException('Параметр forum_id обязателен.');
        }

        if (!Loader::includeModule('forum')) {
            throw new \RuntimeException('Модуль forum не установлен.');
        }

        $forum = ForumTable::getList(array(
            'select' => array('ID', 'NAME', 'TOPICS', 'POSTS'),
            'filter' => array('=ID' => $forumId),
            'limit' => 1,
        ))->fetch();

        if (!$forum) {
            throw new \InvalidArgumentException('Форум #' . $forumId . ' не найден.');
        }

        return array(
            'summary' => 'Удаление форума «' . $forum['NAME'] . '»',
            'payload' => array('args' => array('forum_id' => $forumId)),
            'preview' => 'Форум «' . $forum['NAME'] . '» будет удалён вместе с '
                . (int)$forum['TOPICS'] . ' темами и ' . (int)$forum['POSTS'] . ' сообщениями.',
        );
    }

    public static function apply(array $payload): array
    {
        $forumId = (int)($payload['args']['forum_id'] ?? 0);

        if (!\CForumNew::Delete($forumId)) {
            throw new \RuntimeException('Не удалось удалить форум #' . $forumId . '.');
        }

        return array('forum_id' => $forumId, 'deleted' => true);
    }

    public static function rollback(array $payload): void
    {
        // Восстанавливается через restore_site: см. rollbackNeedsRestore().
    }
}
