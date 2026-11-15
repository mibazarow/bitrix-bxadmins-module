<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

/**
 * Загрузка модуля с сервера обновлений Битрикса и его установка.
 *
 * Два случая:
 *  - модуль уже лежит на диске (в /bitrix/modules или /local/modules) — ставим сразу;
 *  - модуля нет — просим сервер обновлений скачать его для этой лицензии.
 *
 * Второй путь работает только для модулей, доступных лицензии (бесплатные из
 * Marketplace и купленные). Если сервер откажет — вернём его сообщение как есть,
 * без догадок.
 */
class DownloadModuleTool extends AbstractChangeTool
{
    public const DOMAIN = 'security';

    private const MAX_STEPS = 200;

    public static function getName(): string
    {
        return 'download_module';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'download_module',
            'description' => 'Скачивает модуль с сервера обновлений Битрикса (если он доступен лицензии) и устанавливает его. Если модуль уже лежит на диске — просто устанавливает. Требуется согласие владельца: модуль меняет установку.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'module_id' => array('type' => 'string', 'description' => 'ID модуля, например forum или sale'),
                    'download_only' => array('type' => 'boolean', 'description' => 'Только скачать, не устанавливать'),
                ),
                'required' => array('module_id'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $moduleId = self::normalizeId((string)($args['module_id'] ?? ''));

        if (ModuleManager::isModuleInstalled($moduleId)) {
            throw new \InvalidArgumentException('Модуль ' . $moduleId . ' уже установлен.');
        }

        $onDisk = self::onDisk($moduleId);
        $downloadOnly = ($args['download_only'] ?? false) === true;

        $lines = array('Модуль: ' . $moduleId);

        if ($onDisk) {
            $lines[] = 'Файлы уже на диске — будет выполнена установка.';
        } else {
            $lines[] = 'Файлов нет — модуль будет запрошен у сервера обновлений Битрикса.';
            $lines[] = 'Скачивание работает только для модулей, доступных лицензии этого сайта.';
        }

        if ($downloadOnly) {
            $lines[] = 'Только скачивание, без установки.';
        }

        return array(
            'summary' => ($downloadOnly ? 'Загрузка модуля ' : 'Установка модуля ') . $moduleId,
            'payload' => array(
                'args' => array('module_id' => $moduleId, 'download_only' => $downloadOnly),
            ),
            'preview' => implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $moduleId = self::normalizeId((string)($args['module_id'] ?? ''));

        if (ModuleManager::isModuleInstalled($moduleId)) {
            return array('module_id' => $moduleId, 'installed' => true, 'already' => true);
        }

        $downloaded = self::onDisk($moduleId);

        if (!$downloaded) {
            $downloaded = self::download($moduleId);
        }

        if (!empty($args['download_only'])) {
            return array('module_id' => $moduleId, 'downloaded' => $downloaded, 'installed' => false);
        }

        self::install($moduleId);

        return array(
            'module_id' => $moduleId,
            'downloaded' => $downloaded,
            'installed' => ModuleManager::isModuleInstalled($moduleId),
        );
    }

    public static function rollback(array $payload): void
    {
        $moduleId = (string)($payload['result']['module_id'] ?? '');
        if ($moduleId === '' || empty($payload['result']['installed'])) {
            return;
        }

        // Снимаем модуль, но файлы оставляем: удаление кода — отдельное решение владельца.
        if (ModuleManager::isModuleInstalled($moduleId)) {
            ModuleManager::unRegisterModule($moduleId);
        }
    }

    /** Просит сервер обновлений скачать модуль для этой лицензии. */
    private static function download(string $moduleId): bool
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/classes/general/update_client_partner.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/classes/general/update_client.php';

        $error = '';
        $lang = defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru';

        // 1. Есть ли модуль для этой лицензии.
        $updates = \CUpdateClientPartner::GetUpdatesList(
            $error,
            $lang,
            'Y',
            array($moduleId),
            array('fullmoduleinfo' => 'Y')
        );

        if ($error !== '') {
            throw new \RuntimeException('Сервер обновлений: ' . self::clean($error));
        }

        if (!self::listed($updates, $moduleId)) {
            throw new \RuntimeException(
                'Модуль ' . $moduleId . ' недоступен этой лицензии на сервере обновлений. '
                . 'Установите его вручную: Marketplace в админке, либо скопируйте файлы в /local/modules.'
            );
        }

        // 2. Скачивание архива.
        $description = array();
        $error = '';

        \CUpdateClientPartner::LoadModulesUpdates($error, $description, $lang, 'Y', array($moduleId), true);

        if ($error !== '') {
            throw new \RuntimeException('Не удалось скачать модуль: ' . self::clean($error));
        }

        // 3. Распаковка по шагам.
        $updatesDir = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/updates';

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $error = '';
            $result = \CUpdateClient::UpdateStepModules($updatesDir, $error);

            if ($error !== '') {
                throw new \RuntimeException('Ошибка распаковки: ' . self::clean($error));
            }

            // 0/false — шагов больше нет.
            if (empty($result)) {
                break;
            }
        }

        $error = '';
        \CUpdateClient::finalizeModuleUpdate($error);

        if ($error !== '') {
            throw new \RuntimeException('Ошибка завершения загрузки: ' . self::clean($error));
        }

        if (!self::onDisk($moduleId)) {
            throw new \RuntimeException('Модуль скачан, но файлы не найдены на диске.');
        }

        return true;
    }

    private static function install(string $moduleId): void
    {
        if (!self::onDisk($moduleId)) {
            throw new \RuntimeException('Файлы модуля ' . $moduleId . ' не найдены.');
        }

        if (!Loader::includeModule($moduleId)) {
            throw new \RuntimeException('Не удалось загрузить модуль ' . $moduleId . ' после скачивания.');
        }

        $module = \CModule::CreateModuleObject($moduleId);
        if (!is_object($module)) {
            throw new \RuntimeException('Не удалось создать объект установки модуля ' . $moduleId . '.');
        }

        if (method_exists($module, 'DoInstall')) {
            $module->DoInstall();
        }

        ModuleManager::registerModule($moduleId);

        if (!ModuleManager::isModuleInstalled($moduleId)) {
            throw new \RuntimeException('Модуль ' . $moduleId . ' не зарегистрировался после установки.');
        }
    }

    /** @param mixed $updates результат GetUpdatesList */
    private static function listed($updates, string $moduleId): bool
    {
        if (!is_array($updates)) {
            return false;
        }

        // Ответ бывает и плоским списком модулей, и вложенным в MODULES/UPDATE.
        $haystack = json_encode($updates);

        return is_string($haystack) && str_contains($haystack, '"' . $moduleId . '"');
    }

    private static function onDisk(string $moduleId): bool
    {
        $root = (string)$_SERVER['DOCUMENT_ROOT'];

        return is_dir($root . '/bitrix/modules/' . $moduleId)
            || is_dir($root . '/local/modules/' . $moduleId);
    }

    private static function normalizeId(string $moduleId): string
    {
        $moduleId = strtolower(trim($moduleId));

        if (preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)*$/', $moduleId) !== 1) {
            throw new \InvalidArgumentException('ID модуля: латиница, цифры, подчёркивание и точка, например forum.');
        }

        return $moduleId;
    }

    private static function clean(string $text): string
    {
        $text = trim(strip_tags($text));

        return $text !== '' ? mb_substr($text, 0, 400) : 'неизвестная ошибка';
    }
}
