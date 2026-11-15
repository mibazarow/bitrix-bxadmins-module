<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

/** Удаление агента (фоновой задачи). */
class DeleteAgentTool extends AbstractChangeTool
{
    public const DOMAIN = 'automation';

    public static function getName(): string
    {
        return 'delete_agent';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'delete_agent',
            'description' => 'Удаляет агента по ID. Агенты самого харнеса защищены.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'agent_id' => array('type' => 'integer', 'description' => 'ID агента из list_agents'),
                ),
                'required' => array('agent_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $agentId = (int)($args['agent_id'] ?? 0);
        if ($agentId <= 0) {
            throw new \InvalidArgumentException('Параметр agent_id обязателен.');
        }

        // Внимание: CAgent::GetList игнорирует фильтр с префиксом '=' и вернёт первую
        // строку — для агентов используем легаси-формат 'ID' => ....
        $agent = \CAgent::GetList(array('ID' => 'ASC'), array('ID' => $agentId))->Fetch();
        if (!$agent) {
            throw new \InvalidArgumentException('Агент #' . $agentId . ' не найден.');
        }

        if (self::isProtected((string)$agent['NAME'])) {
            throw new \InvalidArgumentException('Этот агент обслуживает сам харнес — удалить его нельзя.');
        }

        return array(
            'summary' => 'Удаление агента #' . $agentId,
            'payload' => array(
                'args' => array('agent_id' => $agentId),
                // Полный снимок: по нему агент восстанавливается при откате.
                'before' => array(
                    'NAME' => (string)$agent['NAME'],
                    'MODULE_ID' => (string)$agent['MODULE_ID'],
                    'SORT' => (int)$agent['SORT'],
                    'INTERVAL' => (int)$agent['INTERVAL'],
                    'PERIOD' => (string)$agent['PERIOD'],
                    'ACTIVE' => (string)$agent['ACTIVE'],
                    'NEXT_EXEC' => $agent['NEXT_EXEC'] instanceof \Bitrix\Main\Type\DateTime
                        ? $agent['NEXT_EXEC']->format('Y-m-d H:i:s')
                        : (string)$agent['NEXT_EXEC'],
                    'USER_ID' => (int)($agent['USER_ID'] ?? 0),
                    'IS_PERIOD' => (string)($agent['IS_PERIOD'] ?? 'N'),
                ),
            ),
            'preview' => 'Будет удалён агент #' . $agentId . ' (' . $agent['MODULE_ID'] . '): '
                . mb_substr((string)$agent['NAME'], 0, 200),
        );
    }

    public static function apply(array $payload): array
    {
        $agentId = (int)($payload['args']['agent_id'] ?? 0);

        if (!\CAgent::Delete($agentId)) {
            throw new \RuntimeException('Не удалось удалить агента.');
        }

        return array('agent_id' => $agentId, 'deleted' => true);
    }

    public static function rollback(array $payload): void
    {
        $before = (array)($payload['before'] ?? array());
        if ($before === array()) {
            return;
        }

        $fields = $before;
        unset($fields['ID']);

        if (!empty($fields['NEXT_EXEC'])) {
            try {
                $fields['NEXT_EXEC'] = new \Bitrix\Main\Type\DateTime((string)$fields['NEXT_EXEC']);
            } catch (\Throwable $e) {
                unset($fields['NEXT_EXEC']);
            }
        }

        // Легаси-исключение: регистрация агента — только CAgent::Add.
        if (!\CAgent::Add($fields)) {
            throw new \RuntimeException('Не удалось восстановить агента при откате.');
        }
    }

    /** Агенты модуля, без которых харнес перестанет работать. */
    private static function isProtected(string $name): bool
    {
        return str_contains($name, 'IndexAgent')
            || str_contains($name, 'MaintenanceAgent')
            || str_contains($name, 'DaemonAgent');
    }
}
