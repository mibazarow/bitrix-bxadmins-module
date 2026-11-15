<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Forum\TopicTable;
use Bitrix\Main\Loader;

/** Удаление темы форума вместе с сообщениями. */
class DeleteForumTopicTool extends AbstractChangeTool
{
    public const DOMAIN = 'collaboration';

    public static function getName(): string
    {
        return 'delete_forum_topic';
    }

    public static function rollbackNeedsRestore(): bool
    {
        // Тема удаляется вместе с сообщениями и счётчиками: точечно восстановить нельзя.
        return true;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_forum_topic',
            'description' => 'Удаляет тему форума со всеми сообщениями. Показывает число сообщений.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'topic_id' => array('type' => 'integer', 'description' => 'ID темы'),
                ),
                'required' => array('topic_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $topicId = (int)($args['topic_id'] ?? 0);
        if ($topicId <= 0) {
            throw new \InvalidArgumentException('Параметр topic_id обязателен.');
        }

        if (!Loader::includeModule('forum')) {
            throw new \RuntimeException('Модуль forum недоступен.');
        }

        $topic = TopicTable::getById($topicId)->fetch();
        if (!$topic) {
            throw new \InvalidArgumentException('Тема #' . $topicId . ' не найдена.');
        }

        return array(
            'summary' => 'Удаление темы «' . $topic['TITLE'] . '»',
            'payload' => array(
                'args' => array('topic_id' => $topicId),
                'before' => array('title' => (string)$topic['TITLE'], 'posts' => (int)$topic['POSTS']),
            ),
            'preview' => 'Будет удалена тема «' . $topic['TITLE'] . '» (ID ' . $topicId . ')'
                . ' вместе с сообщениями: ' . (int)$topic['POSTS'] . '.',
        );
    }

    public static function apply(array $payload): array
    {
        $topicId = (int)($payload['args']['topic_id'] ?? 0);

        // Легаси-исключение: удаление темы со счётчиками форума — только CForumTopic.
        $topic = new \CForumTopic();
        if (!$topic->Delete($topicId)) {
            throw new \RuntimeException(\Mibazarow\Bxadmins\Service\LegacyError::message($topic, 'Не удалось удалить тему.'));
        }

        return array('topic_id' => $topicId, 'deleted' => true);
    }

    public static function rollback(array $payload): void
    {
        throw new \RuntimeException('Удалённая тема восстанавливается из SQL-дампа.');
    }
}
