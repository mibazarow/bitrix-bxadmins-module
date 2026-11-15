<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Forum\Message;
use Bitrix\Forum\Topic;
use Bitrix\Forum\TopicTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

/**
 * Создание темы форума.
 *
 * Тема и первое сообщение создаются через D7-ORM форума: легаси-обёртки
 * (CForumTopic::Add, CForumMessage::Add) молча возвращают false без текста ошибки,
 * а CForumMessage::Add вообще относится к файлам, а не к сообщениям.
 */
class CreateForumTopicTool extends AbstractChangeTool
{
    public const DOMAIN = 'collaboration';

    public static function getName(): string
    {
        return 'create_forum_topic';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_forum_topic',
            'description' => 'Создаёт тему форума с первым сообщением.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'forum_id' => array('type' => 'integer', 'description' => 'ID форума'),
                    'title' => array('type' => 'string', 'description' => 'Заголовок темы'),
                    'message' => array('type' => 'string', 'description' => 'Текст первого сообщения'),
                    'author_id' => array('type' => 'integer', 'description' => 'ID автора, по умолчанию текущий пользователь'),
                ),
                'required' => array('forum_id', 'title', 'message'),
            ),
        );
    }

    public static function preview(array $args, array $context = array()): array
    {
        $forumId = (int)($args['forum_id'] ?? 0);
        $title = IblockElementFields::name($args['title'] ?? null);
        $message = trim((string)($args['message'] ?? ''));

        if ($forumId <= 0) {
            throw new \InvalidArgumentException('Параметр forum_id обязателен.');
        }

        if ($message === '') {
            throw new \InvalidArgumentException('Текст сообщения обязателен.');
        }

        if (!Loader::includeModule('forum')) {
            throw new \RuntimeException('Модуль forum недоступен.');
        }

        $forum = \Bitrix\Forum\ForumTable::getById($forumId)->fetch();
        if (!$forum) {
            throw new \InvalidArgumentException('Форум #' . $forumId . ' не найден.');
        }

        // Демон вызывает инструмент без сессии, поэтому автор — из контекста.
        $authorId = (int)($args['author_id'] ?? 0)
            ?: (int)($context['user_id'] ?? 0)
            ?: (int)\Bitrix\Main\Engine\CurrentUser::get()->getId();

        $author = self::author($authorId);

        return array(
            'summary' => 'Тема «' . $title . '» в форуме «' . $forum['NAME'] . '»',
            'payload' => array(
                'args' => array(
                    'forum_id' => $forumId,
                    'title' => $title,
                    'message' => $message,
                    'author_id' => $authorId,
                    'author_name' => $author['name'],
                    'author_email' => $author['email'],
                ),
            ),
            'preview' => 'Новая тема «' . $title . '» в форуме «' . $forum['NAME'] . "»\n\n" . mb_substr($message, 0, 1200),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $forumId = (int)$args['forum_id'];
        $authorId = (int)$args['author_id'];
        $authorName = (string)$args['author_name'];
        $now = new DateTime();

        $topicResult = TopicTable::add(array(
            'FORUM_ID' => $forumId,
            'TITLE' => (string)$args['title'],
            'STATE' => Topic::STATE_OPENED,
            'APPROVED' => Topic::APPROVED_APPROVED,
            'USER_START_ID' => $authorId,
            'USER_START_NAME' => $authorName,
            'START_DATE' => $now,
            'LAST_POSTER_ID' => $authorId,
            'LAST_POSTER_NAME' => $authorName,
            'LAST_POST_DATE' => $now,
            'ABS_LAST_POST_DATE' => $now,
        ));

        if (!$topicResult->isSuccess()) {
            throw new \RuntimeException(
                'Не удалось создать тему: ' . implode('; ', $topicResult->getErrorMessages())
            );
        }

        $topicId = (int)$topicResult->getId();
        $topic = Topic::getById($topicId);

        if ($topic === null) {
            throw new \RuntimeException('Не удалось прочитать созданную тему #' . $topicId . '.');
        }

        // Message::create (в отличие от add) обновляет счётчики темы/форума и поисковый индекс.
        $messageResult = Message::create($topic, array(
            'USE_SMILES' => 'Y',
            'NEW_TOPIC' => 'Y',
            'APPROVED' => Message::APPROVED_APPROVED,
            'POST_DATE' => $now,
            'POST_MESSAGE' => (string)$args['message'],
            'AUTHOR_ID' => $authorId,
            'AUTHOR_NAME' => $authorName,
            'AUTHOR_EMAIL' => (string)$args['author_email'],
        ));

        if (!$messageResult->isSuccess()) {
            // Тема без сообщения бессмысленна — убираем её.
            \CForumTopic::Delete($topicId);

            throw new \RuntimeException(
                'Не удалось создать сообщение темы: ' . implode('; ', $messageResult->getErrorMessages())
            );
        }

        return array(
            'topic_id' => $topicId,
            'message_id' => (int)$messageResult->getId(),
            'forum_id' => $forumId,
        );
    }

    public static function rollback(array $payload): void
    {
        $topicId = (int)($payload['result']['topic_id'] ?? 0);
        if ($topicId <= 0) {
            return;
        }

        // Удаляет тему вместе с сообщениями и обновляет счётчики форума.
        \CForumTopic::Delete($topicId);
    }

    /** @return array{name:string,email:string} */
    public static function author(int $authorId): array
    {
        if ($authorId <= 0) {
            return array('name' => 'Гость', 'email' => '');
        }

        $user = UserTable::getList(array(
            'select' => array('NAME', 'LAST_NAME', 'EMAIL'),
            'filter' => array('=ID' => $authorId),
            'limit' => 1,
        ))->fetch();

        if (!$user) {
            return array('name' => 'Гость', 'email' => '');
        }

        $name = trim((string)$user['NAME'] . ' ' . (string)$user['LAST_NAME']);

        return array(
            'name' => $name !== '' ? $name : 'Пользователь #' . $authorId,
            'email' => (string)($user['EMAIL'] ?? ''),
        );
    }
}
