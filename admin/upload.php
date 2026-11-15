<?php

/**
 * Загрузка файла в чат. Только администратор и только с валидным sessid.
 */

const ADMIN_MODULE_NAME = 'mibazarow.bxadmins';

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Mibazarow\Bxadmins\Service\AttachmentService;

global $USER;

header('Content-Type: application/json; charset=utf-8');

if (!$USER->IsAdmin()) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'error' => 'Доступ только администратору.'));
    exit;
}

if (!check_bitrix_sessid()) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'error' => 'Сессия устарела, обновите страницу.'));
    exit;
}

if (!Loader::includeModule(ADMIN_MODULE_NAME)) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => 'Модуль не установлен.'));
    exit;
}

$file = $_FILES['file'] ?? null;
if (!is_array($file)) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Файл не передан.'));
    exit;
}

try {
    $meta = AttachmentService::save($file, (int)$USER->GetID());
    echo json_encode(array('ok' => true, 'file' => $meta), JSON_UNESCAPED_UNICODE);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => 'Не удалось сохранить файл.'), JSON_UNESCAPED_UNICODE);
}
