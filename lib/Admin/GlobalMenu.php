<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Admin;

use Bitrix\Main\Localization\Loc;

/**
 * Раздел BXADMIN-S в глобальном меню админки. Пункты раздела собирает admin/menu.php,
 * ядро подставляет их по parent_menu.
 */
class GlobalMenu
{
    public const SECTION_ID = 'global_menu_mibazarow_bxadmins';

    /**
     * @param array $aGlobalMenu Глобальное меню (по ссылке).
     * @param array $aModuleMenu Меню модулей (по ссылке).
     */
    public static function onBuildGlobalMenu(array &$aGlobalMenu, array &$aModuleMenu): void
    {
        global $USER;

        if (!$USER || !$USER->IsAdmin()) {
            return;
        }

        Loc::loadMessages(__FILE__);

        $title = (string)Loc::getMessage('MIBAZAROW_BXADMINS_MENU_TITLE');

        $aGlobalMenu[self::SECTION_ID] = array(
            'menu_id' => 'mibazarow_bxadmins',
            'text' => $title,
            'title' => $title,
            'sort' => 250,
            'items_id' => self::SECTION_ID,
            'items' => array(),
        );
    }
}
