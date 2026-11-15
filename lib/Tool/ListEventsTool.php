<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Application;

/**
 * Зарегистрированные обработчики событий (b_module_to_module).
 */
class ListEventsTool extends AbstractTool
{
    public const DOMAIN = 'events';

    private const LIMIT = 300;
    private const MODULE_PATTERN = '/^[A-Za-z0-9_.\-]{1,50}$/';

    public static function getName(): string
    {
        return 'list_events';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_events',
            'description' => 'Зарегистрированные обработчики событий (b_module_to_module): от какого модуля, событие, куда подписан (модуль/класс/метод/путь), сортировка. Опционально module_id — фильтр по модулю-источнику или получателю. Максимум 300.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'module_id' => array(
                        'type' => 'string',
                        'description' => 'Необязательный ID модуля (from или to), например "main" или "iblock".',
                    ),
                ),
            ),
        );
    }

    /** @return array{module_id:string,count:int,truncated:bool,events:array}|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $moduleId = trim((string)($args['module_id'] ?? ''));

        if ($moduleId !== '' && !preg_match(self::MODULE_PATTERN, $moduleId)) {
            return array('error' => 'Некорректный module_id: разрешены буквы, цифры, "_", "-", "." (1–50 символов).');
        }

        // Прямое чтение БД (read-only) допустимо для аналитики; D7-сущности для
        // b_module_to_module в ядре нет. Имя модуля валидировано regex и экранировано.
        $connection = Application::getConnection();
        $sql = 'SELECT FROM_MODULE_ID, MESSAGE_ID, TO_MODULE_ID, TO_CLASS, TO_METHOD, TO_PATH, SORT'
            . ' FROM b_module_to_module';

        if ($moduleId !== '') {
            $safe = $connection->getSqlHelper()->forSql($moduleId);
            $sql .= " WHERE FROM_MODULE_ID = '" . $safe . "' OR TO_MODULE_ID = '" . $safe . "'";
        }

        $sql .= ' ORDER BY TO_MODULE_ID ASC, MESSAGE_ID ASC, SORT ASC, ID ASC';

        $result = $connection->query($sql, 0, self::LIMIT + 1);
        $events = array();
        $truncated = false;

        while ($row = $result->fetch()) {
            if (count($events) >= self::LIMIT) {
                $truncated = true;
                break;
            }

            $events[] = array(
                'from_module_id' => (string)$row['FROM_MODULE_ID'],
                'message_id' => (string)$row['MESSAGE_ID'],
                'to_module_id' => (string)$row['TO_MODULE_ID'],
                'to_class' => (string)($row['TO_CLASS'] ?? ''),
                'to_method' => (string)($row['TO_METHOD'] ?? ''),
                'to_path' => (string)($row['TO_PATH'] ?? ''),
                'sort' => (int)$row['SORT'],
            );
        }

        $count = count($events);

        return array(
            'module_id' => $moduleId,
            'count' => $count,
            'truncated' => $truncated,
            'events' => $events,
        );
    }
}
