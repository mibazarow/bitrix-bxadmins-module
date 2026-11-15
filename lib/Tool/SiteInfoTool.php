<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\SiteTable;

/**
 * Общие сведения о сайте и окружении.
 */
class SiteInfoTool extends AbstractTool
{
    public const DOMAIN = 'core';

    public static function getName(): string
    {
        return 'site_info';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'site_info',
            'description' => 'Общая информация о сайте: название и URL, версия 1С-Битрикс, версия PHP, документ root, текущий сайт, число установленных модулей.',
            'parameters' => array(
                'type' => 'object',
                'properties' => new \stdClass(),
            ),
        );
    }

    /** @return array{site_id:string,site_name:string,site_url:string,bitrix_version:string,php_version:string,document_root:string,modules_installed_count:int} */
    public static function execute(array $args, array $context = array()): array
    {
        $siteId = defined('SITE_ID') ? SITE_ID : '';
        $siteName = '';

        $site = SiteTable::getList(array(
            'select' => array('LID', 'NAME'),
            'filter' => array('=LID' => $siteId),
            'limit' => 1,
        ))->fetch();

        if ($site) {
            $siteName = $site['NAME'];
        } else {
            $site = SiteTable::getList(array(
                'select' => array('LID', 'NAME'),
                'order' => array('SORT' => 'ASC'),
                'limit' => 1,
            ))->fetch();
            if ($site) {
                $siteId = $site['LID'];
                $siteName = $site['NAME'];
            }
        }

        $request = Context::getCurrent()->getRequest();
        $serverName = (string)$request->getServer()->get('SERVER_NAME');
        $siteUrl = $serverName !== ''
            ? ($request->isHttps() ? 'https://' : 'http://') . $serverName . '/'
            : '';

        return array(
            'site_id' => $siteId,
            'site_name' => $siteName,
            'site_url' => $siteUrl,
            // SM_VERSION — константа ядра, D7-аналога для номера редакции нет.
            'bitrix_version' => defined('SM_VERSION') ? SM_VERSION : '',
            'php_version' => PHP_VERSION,
            'document_root' => Loader::getDocumentRoot(),
            'modules_installed_count' => count(ModuleManager::getInstalledModules()),
        );
    }
}
