<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\SiteTemplateTable;

/** Пункты меню из .menu.php шаблона сайта. */
class GetMenuTool extends AbstractTool
{
    public const DOMAIN = 'design';

    public static function getName(): string
    {
        return 'get_menu';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'get_menu',
            'description' => 'Пункты меню сайта (top или left). Менять — set_menu.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'type' => array('type' => 'string', 'description' => 'Тип меню: top или left, по умолчанию top'),
                ),
            ),
        );
    }

    public static function execute(array $args, array $context = array()): array
    {
        $type = strtolower(trim((string)($args['type'] ?? 'top')));
        if (preg_match('/^[a-z]{1,20}$/', $type) !== 1) {
            return array('error' => 'Некорректный тип меню.');
        }

        $template = self::templateId();
        // Меню лежит в корне сайта (.top.menu.php) либо в каталоге шаблона.
        $candidates = array(
            '/local/templates/' . $template . '/.' . $type . '.menu.php',
            '/.' . $type . '.menu.php',
        );

        foreach ($candidates as $relative) {
            $path = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . $relative;
            if (!is_file($path)) {
                continue;
            }

            $links = (static function (string $file) {
                $aMenuLinks = array();
                include $file;

                return $aMenuLinks;
            })($path);

            $items = array();
            foreach ((array)$links as $link) {
                if (!is_array($link)) {
                    continue;
                }

                $items[] = array(
                    'text' => (string)($link[0] ?? ''),
                    'link' => (string)($link[1] ?? ''),
                    'sort' => isset($link[3]) ? (int)$link[3] : 500,
                );
            }

            return array('type' => $type, 'file' => $relative, 'items' => $items);
        }

        return array('type' => $type, 'file' => null, 'items' => array());
    }

    /** Шаблон сайта: в демоне SITE_ID может быть не определён. */
    private static function templateId(): string
    {
        $siteId = defined('SITE_ID') && (string)SITE_ID !== '' ? (string)SITE_ID : '';

        if ($siteId === '') {
            $site = \Bitrix\Main\SiteTable::getList(array(
                'select' => array('LID', 'TEMPLATE_ID'),
                'filter' => array('=ACTIVE' => 'Y'),
                'order' => array('DEF' => 'DESC', 'SORT' => 'ASC'),
                'limit' => 1,
            ))->fetch();

            return (string)($site['TEMPLATE_ID'] ?? '.default');
        }

        $template = SiteTemplateTable::getList(array(
            'select' => array('TEMPLATE'),
            'filter' => array('=SITE_ID' => $siteId, '=CONDITION' => ''),
            'order' => array('SORT' => 'ASC'),
            'limit' => 1,
        ))->fetch();

        return (string)($template['TEMPLATE'] ?? '.default');
    }
}
