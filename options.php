<?php


declare(strict_types=1);

// Страница настроек модуля в списке модулей: перенаправляет в раздел «Настройки».

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

/** @var CUser $USER */

if (!$USER->IsAdmin()) {
    return;
}

\Bitrix\Main\Localization\Loc::loadMessages(__FILE__);

CAdminMessage::ShowMessage(array(
    'MESSAGE' => \Bitrix\Main\Localization\Loc::getMessage(
        'MIBAZAROW_BXADMINS_OPTIONS_MOVED',
        array('#URL#' => '/local/modules/mibazarow.bxadmins/admin/settings.php?lang=' . LANGUAGE_ID)
    ),
    'TYPE' => 'OK',
));
