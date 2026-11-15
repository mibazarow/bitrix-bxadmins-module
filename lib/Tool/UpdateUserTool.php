<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\UserTable;

/** Изменение пользователя: имя, email, активность, группы. */
class UpdateUserTool extends AbstractChangeTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'update_user';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_user',
            'description' => 'Меняет имя, фамилию, email, активность или группы пользователя. Блокировка — через active=false.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'user_id' => array('type' => 'integer', 'description' => 'ID пользователя'),
                    'name' => array('type' => 'string', 'description' => 'Имя'),
                    'last_name' => array('type' => 'string', 'description' => 'Фамилия'),
                    'email' => array('type' => 'string', 'description' => 'Email'),
                    'active' => array('type' => 'boolean', 'description' => 'Активен; false — блокировка'),
                    'groups' => array(
                        'type' => 'array',
                        'items' => array('type' => 'integer'),
                        'description' => 'Новый список групп (полностью заменяет текущий)',
                    ),
                ),
                'required' => array('user_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $userId = (int)($args['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Параметр user_id обязателен.');
        }

        $user = UserTable::getById($userId)->fetch();
        if (!$user) {
            throw new \InvalidArgumentException('Пользователь #' . $userId . ' не найден.');
        }

        $fields = array();
        if (array_key_exists('name', $args)) {
            $fields['NAME'] = mb_substr(trim((string)$args['name']), 0, 100);
        }
        if (array_key_exists('last_name', $args)) {
            $fields['LAST_NAME'] = mb_substr(trim((string)$args['last_name']), 0, 100);
        }
        if (array_key_exists('email', $args)) {
            $email = trim((string)$args['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Некорректный email.');
            }
            $fields['EMAIL'] = $email;
        }
        if (array_key_exists('active', $args)) {
            $fields['ACTIVE'] = ($args['active'] ?? true) !== false ? 'Y' : 'N';
        }
        if (array_key_exists('groups', $args) && is_array($args['groups'])) {
            $fields['GROUP_ID'] = array_map('intval', $args['groups']);
        }

        if ($fields === array()) {
            throw new \InvalidArgumentException('Не передано ни одного поля для изменения.');
        }

        $before = array(
            'NAME' => (string)($user['NAME'] ?? ''),
            'LAST_NAME' => (string)($user['LAST_NAME'] ?? ''),
            'EMAIL' => (string)($user['EMAIL'] ?? ''),
            'ACTIVE' => (string)$user['ACTIVE'],
        );

        return array(
            'summary' => 'Изменение пользователя #' . $userId . ' (' . $user['LOGIN'] . ')',
            'payload' => array(
                'args' => array('user_id' => $userId, 'fields' => $fields),
                'before' => $before,
            ),
            'preview' => 'Пользователь ' . $user['LOGIN'] . ':' . "\n"
                . implode("\n", array_map(
                    static fn(string $key): string => $key . ': ' . var_export($before[$key] ?? null, true) . ' → ' . var_export($fields[$key] ?? null, true),
                    array_keys($fields)
                )),
        );
    }

    public static function apply(array $payload): array
    {
        $userId = (int)($payload['args']['user_id'] ?? 0);
        $fields = (array)($payload['args']['fields'] ?? array());

        $user = new \CUser();
        if (!$user->Update($userId, $fields)) {
            throw new \RuntimeException(\Mibazarow\Bxadmins\Service\LegacyError::message($user, 'Не удалось обновить пользователя.'));
        }

        return array('user_id' => $userId, 'updated' => array_keys($fields));
    }

    public static function rollback(array $payload): void
    {
        $userId = (int)($payload['args']['user_id'] ?? 0);
        $before = (array)($payload['before'] ?? array());

        if ($userId <= 0 || $before === array()) {
            return;
        }

        $user = new \CUser();
        if (!$user->Update($userId, $before)) {
            throw new \RuntimeException(\Mibazarow\Bxadmins\Service\LegacyError::message($user, 'Не удалось вернуть прежние данные пользователя.'));
        }
    }
}
