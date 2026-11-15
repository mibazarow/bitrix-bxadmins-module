<?php

declare(strict_types=1);

/**
 * Отдача скриншотов агента. Файлы лежат вне docroot, поэтому отдаём их
 * только администратору и только по имени без пути.
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Mibazarow\Bxadmins\Service\BrowserService;

global $USER;

if (!$USER || !$USER->IsAdmin()) {
    http_response_code(403);
    exit;
}

if (!Loader::includeModule('mibazarow.bxadmins')) {
    http_response_code(500);
    exit;
}

$file = (string)($_GET['file'] ?? '');
$path = BrowserService::shotPath($file);

if ($path === null) {
    http_response_code(404);
    exit;
}

$isJpeg = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'jpg';

header('Content-Type: ' . ($isJpeg ? 'image/jpeg' : 'image/png'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; sandbox');

readfile($path);
