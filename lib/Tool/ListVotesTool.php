<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/** Опросы и голосования. */
class ListVotesTool extends AbstractTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'list_votes';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'list_votes',
            'description' => 'Опросы (модуль vote): ID, вопрос, активность, даты начала и конца.',
            'parameters' => array('type' => 'object', 'properties' => new \stdClass()),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        if (!Loader::includeModule('vote')) {
            return array('error' => 'Модуль vote не установлен. Установите его: install_module с module_id=vote.');
        }

        $votes = array();
        $list = \CVote::GetList($by = 's_id', $order = 'desc', array(), false, false);

        while ($row = $list->Fetch()) {
            $votes[] = array(
                'id' => (int)$row['ID'],
                'title' => (string)($row['TITLE'] ?? ''),
                'active' => (string)($row['ACTIVE'] ?? '') === 'Y',
                'start' => (string)($row['DATE_START'] ?? ''),
                'end' => (string)($row['DATE_END'] ?? ''),
            );
        }

        return array('count' => count($votes), 'votes' => $votes);
    }
}
