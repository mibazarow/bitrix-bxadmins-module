<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\SiteTable;
use Bitrix\Main\SiteTemplateTable;

/** Новый сайт в установке (мультисайт). */
class CreateSiteTool extends AbstractChangeTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'create_site';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'create_site',
            'description' => 'Создаёт новый сайт: код, название, домен, каталог, шаблон. Существующие сайты — list_sites.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'lid' => array('type' => 'string', 'description' => 'Код сайта: две латинские буквы, например s2'),
                    'name' => array('type' => 'string', 'description' => 'Название сайта'),
                    'server_name' => array('type' => 'string', 'description' => 'Домен без протокола: shop.example.ru'),
                    'dir' => array('type' => 'string', 'description' => 'Каталог сайта, по умолчанию /'),
                    'template' => array('type' => 'string', 'description' => 'ID шаблона, например eshop_bootstrap_v4'),
                ),
                'required' => array('lid', 'name', 'server_name'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $lid = trim((string)($args['lid'] ?? ''));
        $name = trim((string)($args['name'] ?? ''));
        $serverName = trim((string)($args['server_name'] ?? ''));

        // Битрикс требует ровно два символа: LID сайта совпадает с кодом языка.
        if (preg_match('/^[a-z0-9]{2}$/', $lid) !== 1) {
            throw new \InvalidArgumentException('Код сайта — ровно 2 строчных латинских символа или цифры, например s3.');
        }

        if ($name === '') {
            throw new \InvalidArgumentException('Название сайта обязательно.');
        }

        if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $serverName) !== 1) {
            throw new \InvalidArgumentException('Домен вида shop.example.ru, без https://.');
        }

        if (SiteTable::getList(array('filter' => array('=LID' => $lid), 'limit' => 1))->fetch()) {
            throw new \InvalidArgumentException('Сайт с кодом ' . $lid . ' уже есть.');
        }

        if (SiteTable::getList(array('filter' => array('=SERVER_NAME' => $serverName), 'limit' => 1))->fetch()) {
            throw new \InvalidArgumentException('Домен ' . $serverName . ' уже привязан к сайту.');
        }

        $dir = trim((string)($args['dir'] ?? '/')) ?: '/';
        if (!str_starts_with($dir, '/')) {
            $dir = '/' . $dir;
        }
        $dir = rtrim($dir, '/') . '/';

        $template = trim((string)($args['template'] ?? '')) ?: self::defaultTemplate();

        return array(
            'summary' => 'Новый сайт ' . $lid . ' — ' . $name,
            'payload' => array(
                'args' => array(
                    'lid' => $lid,
                    'name' => $name,
                    'server_name' => $serverName,
                    'dir' => $dir,
                    'template' => $template,
                ),
            ),
            'preview' => 'Сайт ' . $lid . ' — «' . $name . '», домен ' . $serverName
                . ', каталог ' . $dir . ', шаблон ' . $template . '.',
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());

        $site = new \CSite();
        $id = (string)$site->Add(array(
            'LID' => (string)$args['lid'],
            'NAME' => (string)$args['name'],
            'ACTIVE' => 'Y',
            'SORT' => 500,
            'DEF' => 'N',
            'DIR' => (string)$args['dir'],
            'SERVER_NAME' => (string)$args['server_name'],
            'TEMPLATE_ID' => (string)$args['template'],
            'LANGUAGE_ID' => LANGUAGE_ID ?: 'ru',
            // Новое ядро требует региональные настройки прямо в полях сайта.
            'CULTURE_ID' => self::defaultCultureId(),
            'DOC_ROOT' => '',
        ));

        if ($id === '') {
            throw new \RuntimeException(strip_tags((string)$site->LAST_ERROR) ?: 'Не удалось создать сайт.');
        }

        return array(
            'lid' => $id,
            'name' => (string)$args['name'],
            'server_name' => (string)$args['server_name'],
        );
    }

    public static function rollback(array $payload): void
    {
        $lid = (string)($payload['result']['lid'] ?? '');
        if ($lid === '') {
            return;
        }

        // CSite::Delete убирает и язык: сайт и язык — одна запись b_lang.
        $site = new \CSite();
        $site->Delete($lid);
    }

    /** Региональные настройки по умолчанию — первые из существующих. */
    private static function defaultCultureId(): int
    {
        $row = \Bitrix\Main\Localization\CultureTable::getList(array(
            'select' => array('ID'),
            'order' => array('ID' => 'ASC'),
            'limit' => 1,
        ))->fetch();

        return (int)($row['ID'] ?? 0);
    }

    /** Шаблон текущего сайта: новый сайт логично делать таким же. */
    private static function defaultTemplate(): string
    {
        $siteId = defined('SITE_ID') && (string)SITE_ID !== '' ? (string)SITE_ID : '';

        if ($siteId === '') {
            $site = SiteTable::getList(array(
                'select' => array('LID'),
                'filter' => array('=ACTIVE' => 'Y'),
                'order' => array('DEF' => 'DESC', 'SORT' => 'ASC'),
                'limit' => 1,
            ))->fetch();
            $siteId = (string)($site['LID'] ?? '');
        }

        if ($siteId !== '') {
            $row = SiteTemplateTable::getList(array(
                'select' => array('TEMPLATE'),
                'filter' => array('=SITE_ID' => $siteId, '!TEMPLATE' => false),
                'order' => array('SORT' => 'ASC'),
                'limit' => 1,
            ))->fetch();

            if (!empty($row['TEMPLATE'])) {
                return (string)$row['TEMPLATE'];
            }
        }

        return 'eshop_bootstrap_v4';
    }
}
