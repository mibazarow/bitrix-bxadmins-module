<?php

declare(strict_types=1);

/**
 * Самопроверка реестра инструментов BXADMIN-S.
 *
 * Запуск на сервере из корня сайта:
 *   php local/modules/mibazarow.bxadmins/tools/self-test.php
 *
 * Ничего не меняет: проверяет имена, домены, схемы и дубликаты.
 * Возвращает код 1, если есть ошибки.
 */

use Mibazarow\Bxadmins\Config;
use Mibazarow\Bxadmins\Service\ToolRegistry;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 4);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!\Bitrix\Main\Loader::includeModule('mibazarow.bxadmins')) {
    fwrite(STDERR, "Модуль mibazarow.bxadmins не установлен.\n");
    exit(1);
}

$errors = array();
$names = array();
$domains = array();
$toolCount = 0;

foreach (ToolRegistry::tools() as $class) {
    $toolCount++;
    $short = is_string($class) ? $class : get_debug_type($class);

    if (!class_exists($class)) {
        $errors[] = $short . ': класс не найден';

        continue;
    }

    try {
        $name = (string)$class::getName();
        $domain = (string)$class::getDomain();
        $definition = (array)$class::getDefinition();
    } catch (\Throwable $e) {
        $errors[] = $short . ': ' . $e->getMessage();

        continue;
    }

    if (preg_match('/^[a-z][a-z0-9_]{1,60}$/', $name) !== 1) {
        $errors[] = $short . ': некорректное имя "' . $name . '"';
    }

    if (isset($names[$name])) {
        $errors[] = 'Дубликат имени "' . $name . '": ' . $names[$name] . ' и ' . $short;
    }
    $names[$name] = $short;

    if (!isset(Config::DOMAINS[$domain])) {
        $errors[] = $name . ': домен "' . $domain . '" отсутствует в Config::DOMAINS';
    }
    $domains[$domain] = ($domains[$domain] ?? 0) + 1;

    if (($definition['name'] ?? '') !== $name) {
        $errors[] = $name . ': definition.name = "' . (string)($definition['name'] ?? '') . '"';
    }

    $parameters = $definition['parameters'] ?? null;
    if (!is_array($parameters) || ($parameters['type'] ?? '') !== 'object') {
        $errors[] = $name . ': parameters должен быть object';
        continue;
    }

    $properties = $parameters['properties'] ?? array();
    if (!is_array($properties) && !($properties instanceof \stdClass)) {
        $errors[] = $name . ': parameters.properties должен быть массивом или объектом';
        continue;
    }
    $properties = (array)$properties;

    foreach ((array)($parameters['required'] ?? array()) as $required) {
        if (!isset($properties[$required])) {
            $errors[] = $name . ': required "' . $required . '" отсутствует в properties';
        }
    }

    foreach ($properties as $key => $property) {
        if (!is_array($property) || !isset($property['type'])) {
            $errors[] = $name . ': у параметра "' . $key . '" нет type';
        }
    }
}

ksort($domains);
ksort($names);

echo 'Инструментов: ' . $toolCount . PHP_EOL;
echo 'Домены: ' . implode(', ', array_map(
    static function (string $domain, int $count): string {
        return $domain . '=' . $count;
    },
    array_keys($domains),
    array_values($domains)
)) . PHP_EOL;

if ($errors !== array()) {
    echo PHP_EOL . 'Ошибки (' . count($errors) . '):' . PHP_EOL;
    foreach ($errors as $error) {
        echo '  - ' . $error . PHP_EOL;
    }

    exit(1);
}

echo 'OK: реестр согласован.' . PHP_EOL;
exit(0);
