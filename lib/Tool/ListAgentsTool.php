<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Type\DateTime;

/**
 * Агенты (фоновые задачи) сайта.
 */
class ListAgentsTool extends AbstractTool
{
    public const DOMAIN = 'automation';

    private const LIMIT = 200;
    private const NAME_LIMIT = 1000;

    public static function getName(): string
    {
        return 'list_agents';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_agents',
            'description' => 'Агенты (фоновые задачи) сайта: ID, код, модуль, активность, следующий запуск, интервал, сортировка. Максимум 200.',
            'parameters' => array(
                'type' => 'object',
                'properties' => new \stdClass(),
            ),
        );
    }

    /** @return array{count:int,truncated:bool,agents:array} */
    public static function execute(array $args, array $context = array()): array
    {
        // Легаси-исключение: D7-сущности b_agent (AgentTable) в ядре нет,
        // CAgent::GetList — единственный штатный способ прочитать агентов.
        $agents = array();
        $truncated = false;
        $result = \CAgent::GetList(array('ID' => 'ASC'), array());

        while ($row = $result->Fetch()) {
            if (count($agents) >= self::LIMIT) {
                $truncated = true;
                break;
            }

            $agents[] = self::formatRow($row);
        }

        return array(
            'count' => count($agents),
            'truncated' => $truncated,
            'agents' => $agents,
        );
    }

    private static function formatRow(array $row): array
    {
        $name = (string)($row['NAME'] ?? '');
        if (mb_strlen($name) > self::NAME_LIMIT) {
            $name = mb_substr($name, 0, self::NAME_LIMIT) . '…';
        }

        $nextExec = $row['NEXT_EXEC'] ?? '';

        return array(
            'id' => (int)$row['ID'],
            'name' => $name,
            'module_id' => (string)($row['MODULE_ID'] ?? ''),
            'active' => ($row['ACTIVE'] ?? 'N') === 'Y',
            'next_exec' => $nextExec instanceof DateTime ? $nextExec->toString() : (string)$nextExec,
            'agent_interval' => (int)($row['AGENT_INTERVAL'] ?? 0),
            'sort' => (int)($row['SORT'] ?? 100),
        );
    }
}
