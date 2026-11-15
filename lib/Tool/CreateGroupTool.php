<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\GroupTable;

/** Создание группы пользователей. */
class CreateGroupTool extends AbstractChangeTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'create_group';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_group',
            'description' => 'Создаёт группу пользователей: название, описание, тип.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'name' => array('type' => 'string', 'description' => 'Название группы'),
                    'description' => array('type' => 'string', 'description' => 'Описание'),
                    'string_id' => array('type' => 'string', 'description' => 'Символьный код (необязательно)'),
                ),
                'required' => array('name'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $name = IblockElementFields::name($args['name'] ?? null);
        $description = trim((string)($args['description'] ?? ''));
        $stringId = trim((string)($args['string_id'] ?? ''));

        if ($stringId !== '' && !preg_match('/^[A-Za-z0-9_]{1,50}$/', $stringId)) {
            throw new \InvalidArgumentException('Символьный код группы: латиница, цифры, подчёркивание.');
        }

        return array(
            'summary' => 'Создание группы «' . $name . '»',
            'payload' => array(
                'args' => array(
                    'name' => $name,
                    'description' => mb_substr($description, 0, 500),
                    'string_id' => $stringId,
                ),
            ),
            'preview' => 'Новая группа «' . $name . '»' . ($description !== '' ? ': ' . $description : ''),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());

        $fields = array(
            'NAME' => (string)$args['name'],
            'DESCRIPTION' => (string)$args['description'],
        );

        if ((string)$args['string_id'] !== '') {
            $fields['STRING_ID'] = (string)$args['string_id'];
        }

        $result = GroupTable::add($fields);

        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        return array('group_id' => (int)$result->getId(), 'name' => (string)$args['name']);
    }

    public static function rollback(array $payload): void
    {
        $groupId = (int)($payload['result']['group_id'] ?? 0);
        if ($groupId > 0) {
            GroupTable::delete($groupId);
        }
    }
}
