<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Application;

/** Смена пароля пользователя. */
class SetUserPasswordTool extends AbstractChangeTool
{
    public const DOMAIN = 'security';

    private const MIN_LENGTH = 8;

    public static function getName(): string
    {
        return 'set_user_password';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'set_user_password',
            'description' => 'Меняет пароль пользователя. Пароль не сохраняется в истории и не показывается в отчётах. Не короче 8 символов.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'user_id' => array('type' => 'integer', 'description' => 'ID пользователя'),
                    'password' => array('type' => 'string', 'description' => 'Новый пароль (8+ символов)'),
                ),
                'required' => array('user_id', 'password'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $userId = (int)($args['user_id'] ?? 0);
        $password = (string)($args['password'] ?? '');

        if ($userId <= 0) {
            throw new \InvalidArgumentException('Параметр user_id обязателен.');
        }

        if (mb_strlen($password) < self::MIN_LENGTH) {
            throw new \InvalidArgumentException('Пароль должен быть не короче ' . self::MIN_LENGTH . ' символов.');
        }

        $user = \Bitrix\Main\UserTable::getList(array(
            'select' => array('ID', 'LOGIN'),
            'filter' => array('=ID' => $userId),
            'limit' => 1,
        ))->fetch();

        // PASSWORD — приватное поле ORM: хеш читаем прямым запросом.
        $hash = (string)Application::getConnection()->queryScalar(
            'SELECT PASSWORD FROM b_user WHERE ID = ' . $userId
        );

        if (!$user) {
            throw new \InvalidArgumentException('Пользователь #' . $userId . ' не найден.');
        }

        return array(
            'summary' => 'Пароль пользователя #' . $userId,
            'payload' => array(
                'args' => array('user_id' => $userId, 'password' => $password),
                // Хеш старого пароля: откат вернёт его напрямую, без полного дампа.
                'before' => array('password_hash' => $hash),
            ),
            'preview' => 'Пользователь ' . $user['LOGIN'] . ': пароль будет изменён.',
        );
    }

    public static function apply(array $payload): array
    {
        $userId = (int)($payload['args']['user_id'] ?? 0);
        $password = (string)($payload['args']['password'] ?? '');

        $user = new \CUser();
        $ok = $user->Update($userId, array(
            'PASSWORD' => $password,
            'CONFIRM_PASSWORD' => $password,
        ));

        if (!$ok) {
            throw new \RuntimeException(strip_tags((string)$user->LAST_ERROR) ?: 'Не удалось изменить пароль.');
        }

        return array('user_id' => $userId, 'changed' => true);
    }

    public static function rollback(array $payload): void
    {
        $userId = (int)($payload['args']['user_id'] ?? 0);
        $hash = (string)($payload['before']['password_hash'] ?? '');

        if ($userId <= 0 || $hash === '') {
            return;
        }

        // Возвращаем прежний хеш: пароль снова старый, сессии не трогаем.
        $connection = Application::getConnection();
        $connection->queryExecute(
            "UPDATE b_user SET PASSWORD = '" . $connection->getSqlHelper()->forSql($hash) . "'"
            . ' WHERE ID = ' . $userId
        );
    }
}
