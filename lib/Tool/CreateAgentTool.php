<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Mibazarow\Bxadmins\Config;

/**
 * Создание агента (фоновой задачи). Через это агент ставит себе
 * проактивные задачи из чата: «проверяй доступность сайта раз в час».
 */
class CreateAgentTool extends AbstractChangeTool
{
    public const DOMAIN = 'automation';

    private const MIN_INTERVAL = 60;

    public static function getName(): string
    {
        return 'create_agent';
    }

    public static function needsSqlDump(): bool
    {
        // Агент удаляется своим rollback(), полный дамп БД не нужен.
        return false;
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_agent',
            'description' => 'Создаёт агента (фоновую задачу) модуля. Используется для проактивных задач: «проверяй сайт раз в час».',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'name' => array('type' => 'string', 'description' => 'Имя PHP-функции агента, например \\Vendor\\Module\\Agent::run();'),
                    'interval' => array('type' => 'integer', 'description' => 'Интервал в секундах, минимум 60'),
                    'periodic' => array('type' => 'boolean', 'description' => 'Периодический (по умолчанию да)'),
                    'sort' => array('type' => 'integer', 'description' => 'Сортировка, по умолчанию 100'),
                ),
                'required' => array('name'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $name = trim((string)($args['name'] ?? ''));

        if ($name === '') {
            throw new \InvalidArgumentException('Имя агента обязательно.');
        }

        if (!preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*(::[A-Za-z_][A-Za-z0-9_]*)?\s*\(.*\)\s*;?$/', $name)) {
            throw new \InvalidArgumentException('Имя агента должно быть вызовом функции или метода, например \\Vendor\\Module\\Agent::run();');
        }

        $interval = max(self::MIN_INTERVAL, (int)($args['interval'] ?? 3600));
        $periodic = ($args['periodic'] ?? true) !== false;
        $sort = (int)($args['sort'] ?? 100);

        if (!Loader::includeModule('main')) {
            throw new \RuntimeException('Модуль main недоступен.');
        }

        $existing = \CAgent::GetList(
            array('ID' => 'ASC'),
            array('=NAME' => $name)
        )->Fetch();

        if ($existing) {
            throw new \InvalidArgumentException('Агент с таким кодом уже существует (ID ' . $existing['ID'] . ').');
        }

        return array(
            'summary' => 'Новый агент: ' . $name,
            'payload' => array(
                'args' => array(
                    'name' => $name,
                    'interval' => $interval,
                    'periodic' => $periodic,
                    'sort' => $sort,
                    'module_id' => Config::MODULE_ID,
                ),
            ),
            'preview' => 'Будет создан агент ' . $name . ' с интервалом ' . self::humanInterval($interval)
                . ($periodic ? ' (периодический)' : ' (разовый)') . '.',
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());

        // Легаси-исключение: CAgent — штатный механизм фоновых агентов, D7-аналога нет.
        $id = \CAgent::AddAgent(
            (string)$args['name'],
            (string)$args['module_id'],
            !empty($args['periodic']) ? 'Y' : 'N',
            (int)$args['interval'],
            '',
            'Y',
            '',
            (int)$args['sort'],
            false,
            false
        );

        if (!is_int($id) || $id <= 0) {
            throw new \RuntimeException('Не удалось создать агента.');
        }

        return array('agent_id' => (int)$id, 'name' => (string)$args['name']);
    }

    public static function rollback(array $payload): void
    {
        $agentId = (int)($payload['result']['agent_id'] ?? 0);
        if ($agentId > 0) {
            \CAgent::Delete($agentId);
        }
    }

    private static function humanInterval(int $seconds): string
    {
        if ($seconds % 3600 === 0) {
            return ($seconds / 3600) . ' ч';
        }

        if ($seconds % 60 === 0) {
            return ($seconds / 60) . ' мин';
        }

        return $seconds . ' с';
    }
}
