<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\UserGroupTable;
use Bitrix\Main\UserTable;

/** Пользователи сайта. */
class ListUsersTool extends AbstractTool
{
    public const DOMAIN = 'core';

    private const LIMIT = 100;

    public static function getName(): string
    {
        return 'list_users';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_users',
            'description' => 'Пользователи сайта: ID, логин, имя, email, активность, группы. Поиск по логину/email/имени, фильтр по группе. Максимум 100.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'search' => array('type' => 'string', 'description' => 'Подстрока логина, email, имени или фамилии'),
                    'group_id' => array('type' => 'integer', 'description' => 'Только пользователи этой группы'),
                    'active_only' => array('type' => 'boolean', 'description' => 'Только активные'),
                    'offset' => array('type' => 'integer', 'description' => 'Пропустить N записей'),
                ),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,users:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $filter = array();

        if (($args['active_only'] ?? false) === true) {
            $filter['=ACTIVE'] = 'Y';
        }

        $search = trim((string)($args['search'] ?? ''));
        if ($search !== '') {
            $filter['LOGIC'] = 'OR';
            $filter['%LOGIN'] = $search;
            $filter['%EMAIL'] = $search;
            $filter['%NAME'] = $search;
            $filter['%LAST_NAME'] = $search;
        }

        $groupIds = array();

        if ((int)($args['group_id'] ?? 0) > 0) {
            foreach (UserGroupTable::getList(array(
                'select' => array('USER_ID'),
                'filter' => array('=GROUP_ID' => (int)$args['group_id']),
                'limit' => 5000,
            ))->fetchAll() as $row) {
                $groupIds[] = (int)$row['USER_ID'];
            }

            if ($groupIds === array()) {
                return array('count' => 0, 'truncated' => false, 'users' => array());
            }

            $filter['=ID'] = $groupIds;
        }

        $rows = UserTable::getList(array(
            'select' => array('ID', 'LOGIN', 'NAME', 'LAST_NAME', 'EMAIL', 'ACTIVE', 'LAST_LOGIN', 'DATE_REGISTER'),
            'filter' => $filter,
            'order' => array('ID' => 'ASC'),
            'offset' => max(0, (int)($args['offset'] ?? 0)),
            'limit' => self::LIMIT + 1,
        ))->fetchAll();

        $truncated = count($rows) > self::LIMIT;
        $rows = array_slice($rows, 0, self::LIMIT);

        $userIds = array_column($rows, 'ID');
        $groups = self::groupsByUser($userIds);
        $users = array();

        foreach ($rows as $row) {
            $id = (int)$row['ID'];
            $users[] = array(
                'id' => $id,
                'login' => (string)$row['LOGIN'],
                'name' => trim((string)$row['NAME'] . ' ' . (string)$row['LAST_NAME']),
                'email' => (string)$row['EMAIL'],
                'active' => (string)$row['ACTIVE'] === 'Y',
                'groups' => $groups[$id] ?? array(),
                'last_login' => $row['LAST_LOGIN'] instanceof \Bitrix\Main\Type\Date
                    ? $row['LAST_LOGIN']->toString()
                    : (string)($row['LAST_LOGIN'] ?? ''),
            );
        }

        return array('count' => count($users), 'truncated' => $truncated, 'users' => $users);
    }

    /** @param array $userIds @return array<int,array<int,string>> */
    private static function groupsByUser(array $userIds): array
    {
        if ($userIds === array()) {
            return array();
        }

        $result = array();

        foreach (UserGroupTable::getList(array(
            'select' => array('USER_ID', 'GROUP_ID', 'GROUP.NAME'),
            'filter' => array('=USER_ID' => $userIds),
        ))->fetchAll() as $row) {
            $result[(int)$row['USER_ID']][] = array(
                'id' => (int)$row['GROUP_ID'],
                'name' => (string)($row['MAIN_USER_GROUP_GROUP_NAME'] ?? ''),
            );
        }

        return $result;
    }
}
