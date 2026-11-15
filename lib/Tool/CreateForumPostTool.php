<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Forum\Message;
use Bitrix\Forum\Topic;
use Bitrix\Forum\TopicTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

/**
 * Ответ в теме форума.
 *
 * Сообщение создаётся через D7-ORM форума (Message::add): он же обновляет
 * счётчики темы и форума. Легаси CForumMessage::Add работает с файлами,
 * а не с сообщениями, и молча возвращает false.
 */
class CreateForumPostTool extends AbstractChangeTool
{
    public const DOMAIN = 'collaboration';

    public static function getName(): string
    {
        return 'create_forum_post';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_forum_post',
            'description' => 'Добавляет сообщение в существующую тему форума.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'topic_id' => array('type' => 'integer', 'description' => 'ID темы'),
                    'message' => array('type' => 'string', 'description' => 'Текст сообщения'),
                    'author_id' => array('type' => 'integer', 'description' => 'ID автора, по умолчанию текущий пользователь'),
                ),
                'required' => array('topic_id', 'message'),
            ),
        );
    }

    public static function preview(array $args, array $context = array()): array
    {
        $topicId = (int)($args['topic_id'] ?? 0);
        $message = trim((string)($args['message'] ?? ''));

        if ($topicId <= 0) {
            throw new \InvalidArgumentException('Параметр topic_id обязателен.');
        }

        if ($message === '') {
            throw new \InvalidArgumentException('Текст сообщения обязателен.');
        }

        if (!Loader::includeModule('forum')) {
            throw new \RuntimeException('Модуль forum недоступен.');
        }

        $topic = TopicTable::getById($topicId)->fetch();
        if (!$topic) {
            throw new \InvalidArgumentException('Тема #' . $topicId . ' не найдена.');
        }

        // Демон вызывает инструмент без сессии, поэтому автор — из контекста.
        $authorId = (int)($args['author_id'] ?? 0)
            ?: (int)($context['user_id'] ?? 0)
            ?: (int)\Bitrix\Main\Engine\CurrentUser::get()->getId();

        $author = CreateForumTopicTool::author($authorId);

        return array(
            'summary' => 'Сообщение в теме «' . $topic['TITLE'] . '»',
            'payload' => array(
                'args' => array(
                    'topic_id' => $topicId,
                    'message' => $message,
                    'author_id' => $authorId,
                    'author_name' => $author['name'],
                    'author_email' => $author['email'],
                ),
            ),
            'preview' => 'Новое сообщение в теме «' . $topic['TITLE'] . "»:\n\n" . mb_substr($message, 0, 1200),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $topicId = (int)$args['topic_id'];

        $topic = Topic::getById($topicId);
        if ($topic === null) {
            throw new \RuntimeException('Тема #' . $topicId . ' не найдена.');
        }

        // Message::create (в отличие от add) обновляет счётчики темы/форума и поисковый индекс.
        $result = Message::create($topic, array(
            'USE_SMILES' => 'Y',
            'APPROVED' => Message::APPROVED_APPROVED,
            'POST_DATE' => new DateTime(),
            'POST_MESSAGE' => (string)$args['message'],
            'AUTHOR_ID' => (int)$args['author_id'],
            'AUTHOR_NAME' => (string)$args['author_name'],
            'AUTHOR_EMAIL' => (string)$args['author_email'],
        ));

        if (!$result->isSuccess()) {
            throw new \RuntimeException(
                'Не удалось добавить сообщение: ' . implode('; ', $result->getErrorMessages())
            );
        }

        return array('message_id' => (int)$result->getId(), 'topic_id' => $topicId);
    }

    public static function rollback(array $payload): void
    {
        $messageId = (int)($payload['result']['message_id'] ?? 0);
        if ($messageId <= 0) {
            return;
        }

        // Message::delete сам пересчитывает счётчики темы и форума.
        Message::delete($messageId);
    }
}
