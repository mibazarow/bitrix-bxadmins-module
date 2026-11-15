<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\GroupTable;

/** Изменение и удаление группы пользователей. */
class UpdateGroupTool extends AbstractChangeTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'update_group';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_group',
            'description' => 'Меняет название или описание группы. С delete=true удаляет группу (кроме служебных).',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'group_id' => array('type' => 'integer', 'description' => 'ID группы'),
                    'name' => array('type' => 'string', 'description' => 'Новое название'),
                    'description' => array('type' => 'string', 'description' => 'Новое описание'),
                    'delete' => array('type' => 'boolean', 'description' => 'Удалить группу'),
                ),
                'required' => array('group_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $groupId = (int)($args['group_id'] ?? 0);
        if ($groupId <= 0) {
            throw new \InvalidArgumentException('Параметр group_id обязателен.');
        }

        $group = GroupTable::getById($groupId)->fetch();
        if (!$group) {
            throw new \InvalidArgumentException('Группа #' . $groupId . ' не найдена.');
        }

        $delete = ($args['delete'] ?? false) === true;

        if ($delete) {
            if ($groupId <= 2) {
                throw new \InvalidArgumentException('Служебную группу (ID ' . $groupId . ') удалять нельзя.');
            }

            return array(
                'summary' => 'Удаление группы «' . $group['NAME'] . '»',
                'payload' => array('args' => array('group_id' => $groupId, 'delete' => true)),
                'preview' => 'Будет удалена группа «' . $group['NAME'] . '» (ID ' . $groupId . ').'
                    . ' Пользователи останутся, но потеряют эту группу.',
            );
        }

        $before = array('NAME' => (string)$group['NAME'], 'DESCRIPTION' => (string)($group['DESCRIPTION'] ?? ''));
        $fields = array();

        if (array_key_exists('name', $args)) {
            $fields['NAME'] = mb_substr(trim((string)$args['name']), 0, 255);
            if ($fields['NAME'] === '') {
                throw new \InvalidArgumentException('Название группы не может быть пустым.');
            }
        }
        if (array_key_exists('description', $args)) {
            $fields['DESCRIPTION'] = mb_substr(trim((string)$args['description']), 0, 500);
        }

        if ($fields === array()) {
            throw new \InvalidArgumentException('Не передано ни одного поля для изменения.');
        }

        $lines = array();
        foreach ($fields as $field => $value) {
            $lines[] = $field . ': ' . var_export($before[$field] ?? null, true) . ' → ' . var_export($value, true);
        }

        return array(
            'summary' => 'Изменение группы #' . $groupId . ' «' . $before['NAME'] . '»',
            'payload' => array(
                'args' => array('group_id' => $groupId, 'fields' => $fields, 'delete' => false),
                'before' => $before,
            ),
            'preview' => implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $groupId = (int)($payload['args']['group_id'] ?? 0);

        if (($payload['args']['delete'] ?? false) === true) {
            GroupTable::delete($groupId);

            return array('group_id' => $groupId, 'deleted' => true);
        }

        $result = GroupTable::update($groupId, (array)($payload['args']['fields'] ?? array()));
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        return array('group_id' => $groupId, 'updated' => array_keys((array)($payload['args']['fields'] ?? array())));
    }

    public static function rollback(array $payload): void
    {
        if (($payload['args']['delete'] ?? false) === true) {
            throw new \RuntimeException('Удалённая группа восстанавливается из SQL-дампа.');
        }

        $groupId = (int)($payload['args']['group_id'] ?? 0);
        $before = (array)($payload['before'] ?? array());

        if ($groupId > 0 && $before !== array()) {
            GroupTable::update($groupId, $before);
        }
    }
}
