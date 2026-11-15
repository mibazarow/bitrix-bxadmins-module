<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\UserGroupTable;

/** Группы пользователя: полная замена списка. */
class SetUserGroupsTool extends AbstractChangeTool
{
    public const DOMAIN = 'security';

    public static function getName(): string
    {
        return 'set_user_groups';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'set_user_groups',
            'description' => 'Заменяет группы пользователя переданным списком. Список групп — list_groups. Группа 1 — администраторы.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'user_id' => array('type' => 'integer', 'description' => 'ID пользователя'),
                    'group_ids' => array(
                        'type' => 'array',
                        'items' => array('type' => 'integer'),
                        'description' => 'Новый список ID групп',
                    ),
                ),
                'required' => array('user_id', 'group_ids'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $userId = (int)($args['user_id'] ?? 0);
        $groupIds = array_values(array_unique(array_map('intval', (array)($args['group_ids'] ?? array()))));

        if ($userId <= 0) {
            throw new \InvalidArgumentException('Параметр user_id обязателен.');
        }

        if ($groupIds === array()) {
            throw new \InvalidArgumentException('Список групп пуст: пользователь останется без прав.');
        }

        $user = \Bitrix\Main\UserTable::getList(array(
            'select' => array('ID', 'LOGIN', 'NAME', 'LAST_NAME'),
            'filter' => array('=ID' => $userId),
            'limit' => 1,
        ))->fetch();

        if (!$user) {
            throw new \InvalidArgumentException('Пользователь #' . $userId . ' не найден.');
        }

        $existing = array();
        foreach (\Bitrix\Main\GroupTable::getList(array('select' => array('ID')))->fetchAll() as $row) {
            $existing[(int)$row['ID']] = true;
        }

        foreach ($groupIds as $groupId) {
            if (!isset($existing[$groupId])) {
                throw new \InvalidArgumentException('Группы #' . $groupId . ' не существует.');
            }
        }

        $before = array();
        foreach (UserGroupTable::getList(array(
            'select' => array('GROUP_ID'),
            'filter' => array('=USER_ID' => $userId),
        ))->fetchAll() as $row) {
            $before[] = (int)$row['GROUP_ID'];
        }

        sort($before);
        $after = $groupIds;
        sort($after);

        if ($before === $after) {
            throw new \InvalidArgumentException('Изменений нет: пользователь уже в этих группах.');
        }

        return array(
            'summary' => 'Группы пользователя #' . $userId,
            'payload' => array(
                'args' => array('user_id' => $userId, 'group_ids' => $groupIds),
                'before' => array('group_ids' => $before),
            ),
            'preview' => 'Пользователь ' . $user['LOGIN'] . ': группы '
                . implode(', ', $before) . ' → ' . implode(', ', $after),
        );
    }

    public static function apply(array $payload): array
    {
        $userId = (int)($payload['args']['user_id'] ?? 0);
        $groupIds = array_values(array_map('intval', (array)($payload['args']['group_ids'] ?? array())));

        // SetUserGroup заменяет список групп целиком (группа 2 «все пользователи» подразумевается).
        if (\CUser::SetUserGroup($userId, $groupIds) === false) {
            throw new \RuntimeException('Не удалось изменить группы пользователя.');
        }

        return array('user_id' => $userId, 'group_ids' => $groupIds);
    }

    public static function rollback(array $payload): void
    {
        $userId = (int)($payload['args']['user_id'] ?? 0);
        $before = array_values(array_map('intval', (array)($payload['before']['group_ids'] ?? array())));

        if ($userId <= 0 || $before === array()) {
            return;
        }

        \CUser::SetUserGroup($userId, $before);
    }
}
