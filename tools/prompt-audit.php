<?php

/**
 * Проверка системного промпта: размер, обязательные блоки, отсутствие утечек.
 *
 * Запуск из корня сайта:
 *   php local/modules/mibazarow.bxadmins/tools/prompt-audit.php
 *
 * Код возврата 1 — есть проблемы. Скрипт ничего не меняет.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 4);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

\Bitrix\Main\Loader::includeModule('mibazarow.bxadmins');

use Mibazarow\Bxadmins\Config;
use Mibazarow\Bxadmins\Service\ToolRegistry;

$failures = array();

$chat = \Mibazarow\Bxadmins\Service\SystemPrompt::build(array('origin' => 'chat', 'user_id' => 1));
$schedule = \Mibazarow\Bxadmins\Service\SystemPrompt::build(array('origin' => 'schedule', 'bypass' => false));

foreach (array('чат' => $chat, 'расписание' => $schedule) as $label => $prompt) {
    $size = mb_strlen($prompt);

    echo $label . ': ' . $size . ' символов, ~' . (int)round($size / 3) . ' токенов' . PHP_EOL;

    if ($size > 14000) {
        $failures[] = $label . ': промпт длиннее 14 000 символов';
    }

    if (str_contains($prompt, '\\n')) {
        $failures[] = $label . ': в тексте остались литеральные \\n';
    }

    foreach (array('INSTALLATION CONTEXT:', 'CAPABILITIES (enabled domains only):', 'HARD RULES (always apply', 'HOW TO WORK:', 'BITRIX SPECIFICS:', 'HOW TO ANSWER:') as $section) {
        if (!str_contains($prompt, $section)) {
            $failures[] = $label . ': нет блока «' . $section . '»';
        }
    }
}

if (!str_contains($chat, 'RUN MODE — CHAT')) {
    $failures[] = 'в промпте для чата нет режима CHAT';
}

if (!str_contains($schedule, 'RUN MODE — SCHEDULE')) {
    $failures[] = 'в промпте для расписания нет режима SCHEDULE';
}

if (!str_contains($chat, 'Always answer the user in Russian')) {
    $failures[] = 'в промпте нет правила отвечать по-русски';
}

// Утечка ключа модели.
$apiKey = trim(Config::llmApiKey());
if ($apiKey !== '' && (str_contains($chat, $apiKey) || str_contains($schedule, $apiKey))) {
    $failures[] = 'в промпт попал API-ключ модели';
}

// Карта доменов: каждый включённый домен упомянут, счётчики сходятся с реестром.
$counts = array();
foreach (ToolRegistry::tools() as $tool) {
    $domain = $tool::getDomain();
    $counts[$domain] = ($counts[$domain] ?? 0) + 1;
}

$labels = array(
    'core' => 'Core', 'content' => 'Content', 'seo' => 'SEO', 'commerce' => 'Commerce',
    'collaboration' => 'Collaboration', 'automation' => 'Automation', 'marketing' => 'Marketing',
    'integrations' => 'Integrations', 'security' => 'Security', 'events' => 'Events', 'design' => 'Design',
);

foreach (Config::DOMAINS as $key => $label) {
    if (!Config::isDomainAllowed($key)) {
        continue;
    }

    $english = $labels[$key] ?? (string)$label;
    if (!str_contains($chat, $english)) {
        $failures[] = 'в промпте нет включённого домена «' . $english . '»';

        continue;
    }

    $expected = (int)($counts[$key] ?? 0);
    if ($key !== 'integrations' && !str_contains($chat, $english . ' — ' . $expected . ' ')) {
        $failures[] = 'счётчик домена «' . $english . '» не совпал (ожидалось ' . $expected . ')';
    }
}

if ($failures === array()) {
    echo 'OK: промпт в порядке' . PHP_EOL;
    exit(0);
}

echo 'ПРОБЛЕМЫ:' . PHP_EOL;
foreach ($failures as $failure) {
    echo '  - ' . $failure . PHP_EOL;
}

exit(1);
