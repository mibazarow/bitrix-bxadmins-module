<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\UserTable;

/**
 * Удаление пользователя. Необратимо; администраторы и сам агент защищены.
 */
class DeleteUserTool extends AbstractChangeTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'delete_user';
    }

    public static function rollbackNeedsRestore(): bool
    {
        // Пароль пользователя невосстановим, а вместе с ним теряются группы и привязки.
        return true;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_user',
            'description' => 'Удаляет пользователя сайта. Администраторов удалять нельзя. Требует подтверждения.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'user_id' => array('type' => 'integer', 'description' => 'ID пользователя'),
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

        $user = UserTable::getList(array(
            'select' => array('ID', 'LOGIN', 'EMAIL', 'NAME', 'LAST_NAME'),
            'filter' => array('=ID' => $userId),
            'limit' => 1,
        ))->fetch();

        if (!$user) {
            throw new \InvalidArgumentException('Пользователь #' . $userId . ' не найден.');
        }

        if (self::isAdmin($userId)) {
            throw new \InvalidArgumentException('Администратора удалять нельзя: ' . $user['LOGIN'] . '.');
        }

        return array(
            'summary' => 'Удаление пользователя ' . $user['LOGIN'],
            'payload' => array(
                'args' => array('user_id' => $userId),
                'before' => array(
                    'login' => (string)$user['LOGIN'],
                    'email' => (string)$user['EMAIL'],
                ),
            ),
            'preview' => 'Будет удалён пользователь ' . $user['LOGIN'] . ' <' . $user['EMAIL'] . '>'
                . ' (' . trim((string)$user['NAME'] . ' ' . (string)$user['LAST_NAME']) . ').'
                . ' Действие необратимо; перед удалением создаётся SQL-дамп.',
        );
    }

    public static function apply(array $payload): array
    {
        $userId = (int)($payload['args']['user_id'] ?? 0);

        if (self::isAdmin($userId)) {
            throw new \RuntimeException('Администратора удалять нельзя.');
        }

        $user = new \CUser();
        if (!$user->Delete($userId)) {
            throw new \RuntimeException(
                \Mibazarow\Bxadmins\Service\LegacyError::message($user, 'Не удалось удалить пользователя.')
            );
        }

        return array('user_id' => $userId, 'deleted' => true);
    }

    public static function rollback(array $payload): void
    {
        // Восстановление — только из SQL-дампа, созданного перед удалением.
        throw new \RuntimeException('Удаление пользователя откатывается восстановлением SQL-дампа.');
    }

    private static function isAdmin(int $userId): bool
    {
        $groups = \CUser::GetUserGroup($userId);

        return in_array(1, array_map('intval', (array)$groups), true);
    }
}
