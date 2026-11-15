<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/** Обращения техподдержки. */
class ListTicketsTool extends AbstractTool
{
    public const DOMAIN = 'collaboration';

    public static function getName(): string
    {
        return 'list_tickets';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_tickets',
            'description' => 'Обращения в техподдержку (модуль support): ID, тема, статус, критичность, дата.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'limit' => array('type' => 'integer', 'description' => 'Сколько вернуть, по умолчанию 30'),
                ),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('support')) {
            return array('error' => 'Модуль support не установлен. Установите его: install_module с module_id=support.');
        }

        $limit = max(1, min(100, (int)($args['limit'] ?? 30)));
        $tickets = array();

        // Легаси-исключение: список обращений отдаёт только CTicket.
        $list = \CTicket::GetList($by = 's_id', $order = 'desc', array(), false, 'N', false, false, false, array(), $limit);

        while ($row = $list->Fetch()) {
            $tickets[] = array(
                'id' => (int)$row['ID'],
                'title' => (string)($row['TITLE'] ?? ''),
                'status' => (string)($row['STATUS_SID'] ?? $row['STATUS'] ?? ''),
                'criticality' => (string)($row['CRITICALITY_SID'] ?? ''),
                'created' => (string)($row['DATE_CREATE'] ?? ''),
                'messages' => (int)($row['MESSAGES'] ?? 0),
            );
        }

        return array('count' => count($tickets), 'tickets' => $tickets);
    }
}
