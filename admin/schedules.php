<?php

declare(strict_types=1);

// Задачи по расписанию: отдельная страница раздела BXADMIN-S.

const ADMIN_MODULE_NAME = 'mibazarow.bxadmins';

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Mibazarow\Bxadmins\Admin\Assets;
use Mibazarow\Bxadmins\Service\ScheduleService;

global $USER, $APPLICATION;

Loc::loadMessages(__FILE__);

if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm(Loc::getMessage('MIBAZAROW_BXADMINS_ACCESS_DENIED'));
}

if (!Loader::includeModule(ADMIN_MODULE_NAME)) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    CAdminMessage::ShowMessage(array(
        'MESSAGE' => Loc::getMessage('MIBAZAROW_BXADMINS_MODULE_NOT_INSTALLED'),
        'TYPE' => 'ERROR',
    ));
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

    return;
}

$schedules = ScheduleService::list((int)$USER->GetID());
$chatUrl = '/bitrix/admin/mibazarow_bxadmins_chat.php?lang=' . LANGUAGE_ID;
$ajaxUrl = '/local/modules/mibazarow.bxadmins/admin/ajax.php';
$sessid = bitrix_sessid();

$APPLICATION->SetTitle(Loc::getMessage('MIBAZAROW_BXADMINS_SCHEDULES_TITLE'));

$APPLICATION->AddHeadString('<link rel="stylesheet" href="' . Assets::url('admin.css') . '">', true);
$APPLICATION->AddHeadString('<script src="' . Assets::url('manage.js') . '"></script>', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>
<div class="bxadmins-manage bxadmins-schedules-page">
    <?php require __DIR__ . '/parts/schedules.php'; ?>
</div>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
