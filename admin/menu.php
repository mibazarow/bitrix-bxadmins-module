<?php


declare(strict_types=1);

// Пункты раздела BXADMIN-S в левом меню админки (parent_menu — GlobalMenu).

use Bitrix\Main\Localization\Loc;

global $USER;

Loc::loadMessages(__FILE__);

if (!$USER || !$USER->IsAdmin()) {
    return false;
}

$langSuffix = '?lang=' . (defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru');

$chat = '/bitrix/admin/mibazarow_bxadmins_chat.php';
$settings = '/bitrix/admin/mibazarow_bxadmins_settings.php';
$manage = '/bitrix/admin/mibazarow_bxadmins_manage.php';
$schedules = '/bitrix/admin/mibazarow_bxadmins_schedules.php';

$aMenu = array(
    'parent_menu' => 'global_menu_mibazarow_bxadmins',
    'section' => 'mibazarow_bxadmins',
    'sort' => 100,
    'text' => (string)Loc::getMessage('MIBAZAROW_BXADMINS_MENU_SECTION'),
    'title' => (string)Loc::getMessage('MIBAZAROW_BXADMINS_MENU_SECTION'),
    'icon' => 'sys_menu_icon',
    'page_icon' => 'sys_page_icon',
    'items_id' => 'menu_mibazarow_bxadmins',
    'module_id' => 'mibazarow.bxadmins',
    'url' => $chat . $langSuffix,
    'more_url' => array($chat, $settings, $manage, $schedules),
    'items' => array(
        array(
            'text' => (string)Loc::getMessage('MIBAZAROW_BXADMINS_MENU_CHAT'),
            'title' => (string)Loc::getMessage('MIBAZAROW_BXADMINS_MENU_CHAT'),
            'url' => $chat . $langSuffix,
            'more_url' => array($chat),
        ),
        array(
            'text' => (string)Loc::getMessage('MIBAZAROW_BXADMINS_MENU_SETTINGS'),
            'title' => (string)Loc::getMessage('MIBAZAROW_BXADMINS_MENU_SETTINGS'),
            'url' => $settings . $langSuffix,
            'more_url' => array($settings),
        ),
        array(
            'text' => (string)Loc::getMessage('MIBAZAROW_BXADMINS_MENU_SCHEDULES'),
            'title' => (string)Loc::getMessage('MIBAZAROW_BXADMINS_MENU_SCHEDULES'),
            'url' => $schedules . $langSuffix,
            'more_url' => array($schedules),
        ),
        array(
            'text' => (string)Loc::getMessage('MIBAZAROW_BXADMINS_MENU_MANAGE'),
            'title' => (string)Loc::getMessage('MIBAZAROW_BXADMINS_MENU_MANAGE'),
            'url' => $manage . $langSuffix,
            'more_url' => array($manage),
        ),
    ),
);

return $aMenu;
