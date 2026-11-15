<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Forum\ForumTable;
use Bitrix\Main\Loader;

/** Правка форума: название, описание, активность, сортировка. */
class UpdateForumTool extends AbstractChangeTool
{
    public const DOMAIN = 'collaboration';

    public static function getName(): string
    {
        return 'update_forum';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_forum',
            'description' => 'Меняет форум: название, описание, активность, сортировку. Список — list_forums.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'forum_id' => array('type' => 'integer', 'description' => 'ID форума'),
                    'name' => array('type' => 'string', 'description' => 'Новое название'),
                    'description' => array('type' => 'string', 'description' => 'Новое описание'),
                    'active' => array('type' => 'boolean', 'description' => 'Активность'),
                    'sort' => array('type' => 'integer', 'description' => 'Сортировка'),
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
            throw new \RuntimeException('Модуль forum не установлен — сначала установите его.');
        }

        $forum = ForumTable::getList(array(
            'select' => array('ID', 'NAME', 'DESCRIPTION', 'ACTIVE', 'SORT'),
            'filter' => array('=ID' => $forumId),
            'limit' => 1,
        ))->fetch();

        if (!$forum) {
            throw new \InvalidArgumentException('Форум #' . $forumId . ' не найден.');
        }

        $fields = array();
        $lines = array();

        foreach (array('NAME' => 'name', 'DESCRIPTION' => 'description') as $field => $argName) {
            if (!array_key_exists($argName, $args)) {
                continue;
            }

            $value = trim((string)$args[$argName]);
            if ($value === '' || $value === (string)($forum[$field] ?? '')) {
                continue;
            }

            $fields[$field] = $value;
            $lines[] = $field === 'NAME'
                ? 'Название: ' . $forum['NAME'] . ' → ' . $value
                : 'Описание обновлено';
        }

        if (array_key_exists('active', $args)) {
            $active = ((bool)$args['active']) ? 'Y' : 'N';
            if ($active !== (string)$forum['ACTIVE']) {
                $fields['ACTIVE'] = $active;
                $lines[] = 'Активность: ' . $forum['ACTIVE'] . ' → ' . $active;
            }
        }

        if (array_key_exists('sort', $args) && (int)$args['sort'] !== (int)$forum['SORT']) {
            $fields['SORT'] = (int)$args['sort'];
            $lines[] = 'Сортировка: ' . $forum['SORT'] . ' → ' . (int)$args['sort'];
        }

        if ($fields === array()) {
            throw new \InvalidArgumentException('Изменений нет: переданные значения совпадают с текущими.');
        }

        return array(
            'summary' => 'Форум #' . $forumId . ': ' . implode(', ', array_keys($fields)),
            'payload' => array(
                'args' => array('forum_id' => $forumId, 'fields' => $fields),
                'before' => array(
                    'NAME' => (string)$forum['NAME'],
                    'DESCRIPTION' => (string)($forum['DESCRIPTION'] ?? ''),
                    'ACTIVE' => (string)$forum['ACTIVE'],
                    'SORT' => (int)$forum['SORT'],
                ),
            ),
            'preview' => 'Форум «' . $forum['NAME'] . "\"\n" . implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $forumId = (int)($payload['args']['forum_id'] ?? 0);
        $fields = (array)($payload['args']['fields'] ?? array());

        $forum = new \CForumNew();
        if (!$forum->Update($forumId, $fields)) {
            throw new \RuntimeException(
                'Не удалось изменить форум: ' . (strip_tags((string)$forum->LAST_ERROR) ?: 'неизвестная ошибка')
            );
        }

        return array('forum_id' => $forumId, 'updated' => array_keys($fields));
    }

    public static function rollback(array $payload): void
    {
        $forumId = (int)($payload['args']['forum_id'] ?? 0);
        $before = (array)($payload['before'] ?? array());

        if ($forumId <= 0 || $before === array()) {
            return;
        }

        $forum = new \CForumNew();
        $forum->Update($forumId, $before);
    }
}
