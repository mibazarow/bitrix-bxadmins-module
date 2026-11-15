<?php

declare(strict_types=1);

/**
 * Регистрирует MCP-сервер в модуле из CLI.
 *
 * Пример:
 *   BXADMINS_SITE_ROOT=/home/bitrix/ext_www/site php register-mcp.php \
 *     --name=BxMCP --url=http://127.0.0.1:3100/
 *
 * Повторный запуск обновляет запись по URL, а не создаёт дубль.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', array('name:', 'url:', 'token::', 'inactive'));
$name = trim((string)($options['name'] ?? ''));
$url = trim((string)($options['url'] ?? ''));
$token = (string)($options['token'] ?? '');
$active = !isset($options['inactive']);

if ($name === '' || $url === '') {
    fwrite(STDERR, "usage: register-mcp.php --name=NAME --url=URL [--token=TOKEN] [--inactive]\n");
    exit(1);
}

$siteRoot = getenv('BXADMINS_SITE_ROOT') ?: getcwd();
$siteRoot = rtrim((string)$siteRoot, '/');
$_SERVER['DOCUMENT_ROOT'] = $siteRoot;

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);
define('STOP_STATISTICS', true);

require $siteRoot . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Mibazarow\Bxadmins\Model\McpServerTable;
use Mibazarow\Bxadmins\Service\McpService;

if (!Loader::includeModule('mibazarow.bxadmins')) {
    fwrite(STDERR, "module mibazarow.bxadmins is not installed\n");
    exit(1);
}

try {
    $row = McpServerTable::getList(array(
        'select' => array('ID'),
        'filter' => array('=URL' => $url),
        'limit' => 1,
    ))->fetch();

    $id = McpService::save($row ? (int)$row['ID'] : 0, $name, $url, $token, $active);
    fwrite(STDOUT, 'registered #' . $id . ' ' . $name . ' ' . $url . "\n");
} catch (\Throwable $e) {
    fwrite(STDERR, 'register failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$lastError = null;
for ($attempt = 1; $attempt <= 6; $attempt++) {
    try {
        $result = McpService::refreshTools($id);
        fwrite(STDOUT, 'tools: ' . (int)$result['count'] . "\n");
        exit(0);
    } catch (\Throwable $e) {
        $lastError = $e;
        if ($attempt < 6) {
            sleep(2);
        }
    }
}

fwrite(STDERR, 'tools refresh failed: ' . ($lastError ? $lastError->getMessage() : 'unknown') . "\n");
fwrite(STDERR, "server saved; tools will be loaded on the next refresh\n");
exit(2);
