<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\UserTable;

/**
 * Создание пользователя. D7-API создания пользователя нет: CUser::Add сам
 * хеширует пароль, ставит группы и шлёт приветственное письмо.
 */
class CreateUserTool extends AbstractChangeTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'create_user';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_user',
            'description' => 'Создаёт пользователя сайта: логин, email, пароль, группы. Пароль не возвращается в ответе.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'login' => array('type' => 'string', 'description' => 'Логин: 3-50 символов, латиница и цифры'),
                    'email' => array('type' => 'string', 'description' => 'Email'),
                    'password' => array('type' => 'string', 'description' => 'Пароль, не короче 8 символов'),
                    'name' => array('type' => 'string', 'description' => 'Имя'),
                    'last_name' => array('type' => 'string', 'description' => 'Фамилия'),
                    'groups' => array(
                        'type' => 'array',
                        'items' => array('type' => 'integer'),
                        'description' => 'ID групп, по умолчанию только зарегистрированные (2)',
                    ),
                    'active' => array('type' => 'boolean', 'description' => 'Активен, по умолчанию да'),
                ),
                'required' => array('login', 'email', 'password'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $login = trim((string)($args['login'] ?? ''));
        $email = trim((string)($args['email'] ?? ''));
        $password = (string)($args['password'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $login)) {
            throw new \InvalidArgumentException('Логин: 3-50 символов, латиница, цифры, точка, дефис, подчёркивание.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Некорректный email.');
        }

        if (mb_strlen($password) < 8) {
            throw new \InvalidArgumentException('Пароль должен быть не короче 8 символов.');
        }

        if (UserTable::getList(array('select' => array('ID'), 'filter' => array('=LOGIN' => $login), 'limit' => 1))->fetch()) {
            throw new \InvalidArgumentException('Пользователь с логином ' . $login . ' уже существует.');
        }

        if (UserTable::getList(array('select' => array('ID'), 'filter' => array('=EMAIL' => $email), 'limit' => 1))->fetch()) {
            throw new \InvalidArgumentException('Пользователь с email ' . $email . ' уже существует.');
        }

        $groups = is_array($args['groups'] ?? null) && $args['groups'] !== array()
            ? array_map('intval', $args['groups'])
            : array(2);

        $payload = array(
            'login' => $login,
            'email' => $email,
            'password' => $password,
            'name' => trim((string)($args['name'] ?? '')),
            'last_name' => trim((string)($args['last_name'] ?? '')),
            'groups' => $groups,
            'active' => ($args['active'] ?? true) !== false ? 'Y' : 'N',
        );

        return array(
            'summary' => 'Создание пользователя ' . $login,
            'payload' => array('args' => $payload),
            'preview' => 'Новый пользователь: ' . $login . ' <' . $email . '>, группы: ' . implode(', ', $groups)
                . '. Пароль сохраняется, но не показывается.',
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());

        $user = new \CUser();
        $id = $user->Add(array(
            'LOGIN' => (string)$args['login'],
            'EMAIL' => (string)$args['email'],
            'PASSWORD' => (string)$args['password'],
            'CONFIRM_PASSWORD' => (string)$args['password'],
            'NAME' => (string)$args['name'],
            'LAST_NAME' => (string)$args['last_name'],
            'ACTIVE' => (string)$args['active'],
            'GROUP_ID' => (array)$args['groups'],
            'LID' => defined('SITE_ID') ? SITE_ID : 's1',
        ));

        if (!$id) {
            throw new \RuntimeException(\Mibazarow\Bxadmins\Service\LegacyError::message($user, 'Не удалось создать пользователя.'));
        }

        return array('user_id' => (int)$id, 'login' => (string)$args['login'], 'email' => (string)$args['email']);
    }

    public static function rollback(array $payload): void
    {
        $id = (int)($payload['result']['user_id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        $user = new \CUser();
        if (!$user->Delete($id)) {
            throw new \RuntimeException(\Mibazarow\Bxadmins\Service\LegacyError::message($user, 'Не удалось удалить пользователя при откате.'));
        }
    }
}
