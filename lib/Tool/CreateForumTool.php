<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/** Новый форум. */
class CreateForumTool extends AbstractChangeTool
{
    public const DOMAIN = 'collaboration';

    public static function getName(): string
    {
        return 'create_forum';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_forum',
            'description' => 'Создаёт форум. Требует установленного модуля forum (install_module).',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'name' => array('type' => 'string', 'description' => 'Название форума'),
                    'description' => array('type' => 'string', 'description' => 'Описание'),
                    'sort' => array('type' => 'integer', 'description' => 'Сортировка, по умолчанию 150'),
                    'active' => array('type' => 'boolean', 'description' => 'Активен, по умолчанию да'),
                ),
                'required' => array('name'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $name = IblockElementFields::name($args['name'] ?? null);

        if (!Loader::includeModule('forum')) {
            throw new \RuntimeException('Модуль forum не установлен — сначала установите его.');
        }

        $siteId = self::siteId();

        return array(
            'summary' => 'Новый форум «' . $name . '»',
            'payload' => array(
                'args' => array(
                    'name' => $name,
                    'description' => mb_substr(trim((string)($args['description'] ?? '')), 0, 2000),
                    'sort' => (int)($args['sort'] ?? 150),
                    'active' => ($args['active'] ?? true) !== false,
                    'site_id' => $siteId,
                ),
            ),
            'preview' => 'Форум «' . $name . '» на сайте ' . $siteId
                . (($args['description'] ?? '') !== '' ? "\n" . $args['description'] : ''),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());

        // Легаси-исключение: создание форума с правами и привязкой к сайту — только CForumNew.
        $forum = new \CForumNew();
        $id = (int)$forum->Add(array(
            'NAME' => (string)$args['name'],
            'DESCRIPTION' => (string)$args['description'],
            'SORT' => (int)$args['sort'],
            'ACTIVE' => !empty($args['active']) ? 'Y' : 'N',
            'SITE_ID' => array((string)$args['site_id']),
        ));

        if ($id <= 0) {
            throw new \RuntimeException(
                'Не удалось создать форум: ' . (strip_tags((string)$forum->LAST_ERROR) ?: 'неизвестная ошибка')
            );
        }

        return array('forum_id' => $id, 'name' => (string)$args['name']);
    }

    public static function rollback(array $payload): void
    {
        $forumId = (int)($payload['result']['forum_id'] ?? 0);
        if ($forumId > 0) {
            \CForumNew::Delete($forumId);
        }
    }

    /** Сайт, к которому привязать форум: в демоне SITE_ID может быть не определён. */
    private static function siteId(): string
    {
        if (defined('SITE_ID') && (string)SITE_ID !== '') {
            return (string)SITE_ID;
        }

        $row = \Bitrix\Main\SiteTable::getList(array(
            'select' => array('LID'),
            'filter' => array('=ACTIVE' => 'Y'),
            'order' => array('DEF' => 'DESC', 'SORT' => 'ASC'),
            'limit' => 1,
        ))->fetch();

        return (string)($row['LID'] ?? 's1');
    }
}
