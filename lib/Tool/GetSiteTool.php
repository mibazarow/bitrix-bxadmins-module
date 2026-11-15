<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\SiteTable;
use Bitrix\Main\SiteTemplateTable;

/**
 * Параметры одного сайта по его LID.
 */
class GetSiteTool extends AbstractTool
{
    public const DOMAIN = 'core';

    private const LID_PATTERN = '/^[A-Za-z0-9_\-]{1,10}$/';

    public static function getName(): string
    {
        return 'get_site';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'get_site',
            'description' => 'Параметры одного сайта по его LID: название, домен, каталог, язык, активность, шаблон. Ошибка, если сайт не найден.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'lid' => array(
                        'type' => 'string',
                        'description' => 'Идентификатор сайта (LID), например "s1".',
                    ),
                ),
                'required' => array('lid'),
            ),
        );
    }

    /** @return array<string,mixed>|array{error:string} */
    public static function execute(array $args, array $context = array()): array
    {
        $lid = trim((string)($args['lid'] ?? ''));

        if ($lid === '' || !preg_match(self::LID_PATTERN, $lid)) {
            return array('error' => 'Некорректный lid: разрешены буквы, цифры, "_", "-" (1–10 символов).');
        }

        $row = SiteTable::getList(array(
            'select' => array(
                'LID', 'NAME', 'DEF', 'ACTIVE', 'SORT', 'DIR',
                'SERVER_NAME', 'LANGUAGE_ID', 'SITE_NAME', 'EMAIL',
            ),
            'filter' => array('=LID' => $lid),
            'limit' => 1,
        ))->fetch();

        if (!$row) {
            return array('error' => 'Сайт с LID "' . $lid . '" не найден.');
        }

        $template = SiteTemplateTable::getList(array(
            'select' => array('TEMPLATE'),
            'filter' => array('=SITE_ID' => $lid),
            'order' => array('SORT' => 'ASC', 'ID' => 'ASC'),
            'limit' => 1,
        ))->fetch();

        return array(
            'lid' => (string)$row['LID'],
            'name' => (string)$row['NAME'],
            'def' => $row['DEF'] === 'Y' || $row['DEF'] === true || $row['DEF'] === 1,
            'active' => $row['ACTIVE'] === 'Y' || $row['ACTIVE'] === true || $row['ACTIVE'] === 1,
            'sort' => (int)$row['SORT'],
            'dir' => (string)($row['DIR'] ?? ''),
            'server_name' => (string)($row['SERVER_NAME'] ?? ''),
            'language_id' => (string)($row['LANGUAGE_ID'] ?? ''),
            'site_name' => (string)($row['SITE_NAME'] ?? ''),
            'email' => (string)($row['EMAIL'] ?? ''),
            'template_id' => $template ? (string)$template['TEMPLATE'] : '',
        );
    }
}
