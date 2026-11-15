<?php

/**
 * Отдаёт файл из чата. Только администратору: файлы лежат вне docroot.
 */

const ADMIN_MODULE_NAME = 'mibazarow.bxadmins';

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Mibazarow\Bxadmins\Service\AttachmentService;

global $USER;

if (!$USER->IsAdmin()) {
    http_response_code(403);
    exit('Доступ только администратору.');
}

if (!Loader::includeModule(ADMIN_MODULE_NAME)) {
    http_response_code(500);
    exit('Модуль не установлен.');
}

$id = (string)($_GET['id'] ?? '');
$path = AttachmentService::path($id);

if ($path === null || !is_file($path)) {
    http_response_code(404);
    exit('Файл не найден.');
}

$name = trim((string)($_GET['name'] ?? ''));
$name = preg_replace('/[\x00-\x1F\x7F]+/', '', $name) ?? '';
$name = basename(str_replace('\\', '/', $name));
if ($name === '') {
    $name = $id;
}

$finfo = new \finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($path) ?: 'application/octet-stream';
// Inline только для заведомо безопасных типов: SVG и прочий «image/*» может
// содержать скрипты и при прямом открытии выполнится на домене админки.
$inlineTypes = array('image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp');
$inline = in_array($mime, $inlineTypes, true);

header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($name) . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; sandbox');
header('Cache-Control: private, max-age=86400');

readfile($path);
exit;
