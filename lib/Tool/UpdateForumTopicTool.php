<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Forum\Topic;
use Bitrix\Forum\TopicTable;
use Bitrix\Main\Loader;

/** Правка и модерация темы форума. */
class UpdateForumTopicTool extends AbstractChangeTool
{
    public const DOMAIN = 'collaboration';

    public static function getName(): string
    {
        return 'update_forum_topic';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_forum_topic',
            'description' => 'Меняет тему форума: заголовок, открыта/закрыта, одобрена/скрыта, закрепление, перенос в другой форум.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'topic_id' => array('type' => 'integer', 'description' => 'ID темы'),
                    'title' => array('type' => 'string', 'description' => 'Новый заголовок'),
                    'closed' => array('type' => 'boolean', 'description' => 'Закрыть тему для новых сообщений'),
                    'approved' => array('type' => 'boolean', 'description' => 'Одобрить (true) или скрыть (false)'),
                    'pinned' => array('type' => 'boolean', 'description' => 'Закрепить тему вверху списка'),
                    'forum_id' => array('type' => 'integer', 'description' => 'Перенести тему в другой форум'),
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
            throw new \RuntimeException('Модуль forum не установлен.');
        }

        $topic = TopicTable::getList(array(
            'select' => array('ID', 'FORUM_ID', 'TITLE', 'STATE', 'APPROVED', 'SORT', 'POSTS'),
            'filter' => array('=ID' => $topicId),
            'limit' => 1,
        ))->fetch();

        if (!$topic) {
            throw new \InvalidArgumentException('Тема #' . $topicId . ' не найдена.');
        }

        $changes = array();
        $lines = array();

        if (array_key_exists('title', $args)) {
            $title = trim((string)$args['title']);
            if ($title !== '' && $title !== (string)$topic['TITLE']) {
                $changes['TITLE'] = $title;
                $lines[] = 'Заголовок: ' . $topic['TITLE'] . ' → ' . $title;
            }
        }

        if (array_key_exists('closed', $args)) {
            $state = ((bool)$args['closed']) ? Topic::STATE_CLOSED : Topic::STATE_OPENED;
            if ($state !== (string)$topic['STATE']) {
                $changes['STATE'] = $state;
                $lines[] = 'Тема: ' . ($state === Topic::STATE_CLOSED ? 'закрыта' : 'открыта');
            }
        }

        if (array_key_exists('approved', $args)) {
            $approved = ((bool)$args['approved']) ? 'Y' : 'N';
            if ($approved !== (string)$topic['APPROVED']) {
                $changes['APPROVED'] = $approved;
                $lines[] = $approved === 'Y' ? 'Тема одобрена' : 'Тема скрыта из списка';
            }
        }

        if (array_key_exists('pinned', $args)) {
            // В форуме «важные» темы отличаются сортировкой: меньше — выше.
            $sort = ((bool)$args['pinned']) ? 100 : 150;
            if ($sort !== (int)$topic['SORT']) {
                $changes['SORT'] = $sort;
                $lines[] = ((bool)$args['pinned']) ? 'Тема закреплена' : 'Тема откреплена';
            }
        }

        $targetForum = (int)($args['forum_id'] ?? 0);
        if ($targetForum > 0 && $targetForum !== (int)$topic['FORUM_ID']) {
            $changes['FORUM_ID'] = $targetForum;
            $lines[] = 'Перенос: форум ' . $topic['FORUM_ID'] . ' → ' . $targetForum;
        }

        if ($changes === array()) {
            throw new \InvalidArgumentException('Изменений нет: тема уже в этом состоянии.');
        }

        return array(
            'summary' => 'Тема «' . $topic['TITLE'] . '»: ' . implode(', ', array_keys($changes)),
            'payload' => array(
                'args' => array('topic_id' => $topicId, 'changes' => $changes),
                'before' => array(
                    'TITLE' => (string)$topic['TITLE'],
                    'STATE' => (string)$topic['STATE'],
                    'APPROVED' => (string)$topic['APPROVED'],
                    'SORT' => (int)$topic['SORT'],
                    'FORUM_ID' => (int)$topic['FORUM_ID'],
                ),
            ),
            'preview' => 'Тема «' . $topic['TITLE'] . "\"\n" . implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $topicId = (int)($payload['args']['topic_id'] ?? 0);
        $changes = (array)($payload['args']['changes'] ?? array());

        $topic = Topic::getById($topicId);
        if ($topic === null) {
            throw new \RuntimeException('Тема #' . $topicId . ' не найдена.');
        }

        // Состояние и модерация меняются отдельными методами: они пересчитывают
        // права и поисковый индекс. Заголовок и сортировка — через edit().
        if (isset($changes['STATE'])) {
            $result = $changes['STATE'] === Topic::STATE_CLOSED ? $topic->close() : $topic->open();
            if (!$result->isSuccess()) {
                throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
            }
            unset($changes['STATE']);
        }

        if (isset($changes['APPROVED'])) {
            $result = $changes['APPROVED'] === 'Y' ? $topic->approve() : $topic->disapprove();
            if (!$result->isSuccess()) {
                throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
            }
            unset($changes['APPROVED']);
        }

        if (isset($changes['FORUM_ID'])) {
            $result = $topic->moveToForum((int)$changes['FORUM_ID']);
            if (!$result->isSuccess()) {
                throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
            }
            unset($changes['FORUM_ID']);
        }

        // Сортировка (закрепление) — напрямую в таблицу: Topic::edit() пропускает SORT.
        if (isset($changes['SORT'])) {
            TopicTable::update($topicId, array('SORT' => (int)$changes['SORT']));
            unset($changes['SORT']);
        }

        if ($changes !== array()) {
            $result = $topic->edit($changes);
            if (!$result->isSuccess()) {
                throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
            }
        }

        return array('topic_id' => $topicId, 'updated' => array_keys((array)($payload['args']['changes'] ?? array())));
    }

    public static function rollback(array $payload): void
    {
        $topicId = (int)($payload['args']['topic_id'] ?? 0);
        $before = (array)($payload['before'] ?? array());

        $topic = $topicId > 0 ? Topic::getById($topicId) : null;
        if ($topic === null || $before === array()) {
            return;
        }

        if (isset($before['STATE'])) {
            $before['STATE'] === Topic::STATE_CLOSED ? $topic->close() : $topic->open();
            unset($before['STATE']);
        }

        if (isset($before['APPROVED'])) {
            $before['APPROVED'] === 'Y' ? $topic->approve() : $topic->disapprove();
            unset($before['APPROVED']);
        }

        if (isset($before['FORUM_ID']) && (int)$before['FORUM_ID'] > 0) {
            $topic->moveToForum((int)$before['FORUM_ID']);
            unset($before['FORUM_ID']);
        }

        if (isset($before['SORT'])) {
            TopicTable::update($topicId, array('SORT' => (int)$before['SORT']));
            unset($before['SORT']);
        }

        if ($before !== array()) {
            $topic->edit($before);
        }
    }
}
