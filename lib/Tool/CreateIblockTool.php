<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;

/**
 * Создание инфоблока. D7-API создания инфоблоков нет: только CIBlock::Add,
 * который сам создаёт таблицы, поля и права.
 */
class CreateIblockTool extends AbstractChangeTool
{
    public const DOMAIN = 'content';

    public static function getName(): string
    {
        return 'create_iblock';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_iblock',
            'description' => 'Создаёт инфоблок (тип, код, название, сайты). Возвращает ID нового инфоблока.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'name' => array('type' => 'string', 'description' => 'Название инфоблока'),
                    'code' => array('type' => 'string', 'description' => 'Символьный код латиницей'),
                    'type' => array('type' => 'string', 'description' => 'Тип инфоблока, например news. По умолчанию news'),
                    'sites' => array(
                        'type' => 'array',
                        'items' => array('type' => 'string'),
                        'description' => 'Коды сайтов, по умолчанию текущий',
                    ),
                    'active' => array('type' => 'boolean', 'description' => 'Активен (по умолчанию да)'),
                    'sort' => array('type' => 'integer', 'description' => 'Сортировка'),
                    'index_elements' => array('type' => 'boolean', 'description' => 'Индексировать элементы для поиска'),
                    'api_code' => array('type' => 'string', 'description' => 'Код для ORM-класса (необязательно)'),
                ),
                'required' => array('name', 'code'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $name = IblockElementFields::name($args['name'] ?? null);
        $code = IblockElementFields::code($args['code'] ?? '');

        if ($code === '') {
            throw new \InvalidArgumentException('Символьный код обязателен для инфоблока.');
        }

        if (!preg_match('/^[a-z0-9_-]+$/', $code)) {
            throw new \InvalidArgumentException('Код инфоблока: только строчная латиница, цифры, дефис и подчёркивание.');
        }

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен.');
        }

        $type = trim((string)($args['type'] ?? 'news')) ?: 'news';
        $sites = is_array($args['sites'] ?? null) && $args['sites'] !== array()
            ? array_values(array_map('strval', $args['sites']))
            : array(self::currentSiteId());

        $existing = \Bitrix\Iblock\IblockTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=CODE' => $code),
            'limit' => 1,
        ))->fetch();

        if ($existing) {
            throw new \InvalidArgumentException('Инфоблок с кодом ' . $code . ' уже существует (ID ' . $existing['ID'] . ').');
        }

        $payload = array(
            'name' => $name,
            'code' => $code,
            'type' => $type,
            'sites' => $sites,
            'active' => IblockElementFields::active($args['active'] ?? true),
            'sort' => IblockElementFields::sort($args['sort'] ?? 500),
            'index_elements' => ($args['index_elements'] ?? true) !== false ? 'Y' : 'N',
            'api_code' => trim((string)($args['api_code'] ?? '')),
        );

        return array(
            'summary' => 'Создание инфоблока «' . $name . '»',
            'payload' => array('args' => $payload),
            'preview' => 'Новый инфоблок «' . $name . '» (код ' . $code . ', тип ' . $type
                . ', сайты: ' . implode(', ', $sites) . ').',
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock недоступен.');
        }

        $iblock = new \CIBlock();
        $id = $iblock->Add(array(
            'NAME' => (string)$args['name'],
            'CODE' => (string)$args['code'],
            'IBLOCK_TYPE_ID' => (string)$args['type'],
            'SITE_ID' => (array)$args['sites'],
            'ACTIVE' => (string)$args['active'],
            'SORT' => (int)$args['sort'],
            'INDEX_ELEMENT' => (string)$args['index_elements'],
            'API_CODE' => (string)$args['api_code'],
            'GROUP_ID' => array(2 => 'R'),
        ));

        if (!$id) {
            throw new \RuntimeException(IblockElementFields::legacyError($iblock, 'Не удалось создать инфоблок.'));
        }

        return array('iblock_id' => (int)$id, 'code' => (string)$args['code'], 'name' => (string)$args['name']);
    }

    public static function rollback(array $payload): void
    {
        $id = (int)($payload['result']['iblock_id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        $iblock = new \CIBlock();
        if (!$iblock->Delete($id)) {
            throw new \RuntimeException(IblockElementFields::legacyError($iblock, 'Не удалось удалить инфоблок при откате.'));
        }
    }

    private static function currentSiteId(): string
    {
        $lid = (string)\Bitrix\Main\Context::getCurrent()->getSite();

        if ($lid !== '') {
            return $lid;
        }

        $site = \Bitrix\Main\SiteTable::getList(array(
            'select' => array('LID'),
            'filter' => array('=ACTIVE' => 'Y'),
            'order' => array('DEF' => 'DESC', 'SORT' => 'ASC'),
            'limit' => 1,
        ))->fetch();

        return (string)($site['LID'] ?? 's1');
    }
}
