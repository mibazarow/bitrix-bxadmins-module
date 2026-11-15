<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Application;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Mibazarow\Bxadmins\Agent\DaemonAgent;
use Mibazarow\Bxadmins\Agent\MaintenanceAgent;
use Mibazarow\Bxadmins\Agent\McpRefreshAgent;
use Mibazarow\Bxadmins\Agent\ScheduleAgent;
use Mibazarow\Bxadmins\Agent\ScraperAgent;
use Mibazarow\Bxadmins\Config;
use Mibazarow\Bxadmins\Service\LlmProfileService;
use Mibazarow\Bxadmins\Service\IndexService;

Loc::loadMessages(__FILE__);

/**
 * Установщик модуля: таблицы, admin-обёртки, регистрация модуля,
 * обработчик глобального меню и агент индексации.
 */
class mibazarow_bxadmins extends CModule
{
    public $MODULE_ID = 'mibazarow.bxadmins';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $PARTNER_NAME;
    public $PARTNER_URI;

    private const GLOBAL_MENU_HANDLER_CLASS = '\\Mibazarow\\Bxadmins\\Admin\\GlobalMenu';
    private const GLOBAL_MENU_HANDLER_METHOD = 'onBuildGlobalMenu';

    public function __construct()
    {
        $arModuleVersion = array();
        include __DIR__ . '/version.php';

        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME = 'BXADMIN-S';
        $this->MODULE_DESCRIPTION = Loc::getMessage('MIBAZAROW_BXADMINS_INSTALL_DESCRIPTION');
        $this->PARTNER_NAME = 'Михаил Базаров';
        $this->PARTNER_URI = 'https://bazarow.ru/';
    }

    public function DoInstall()
    {
        global $APPLICATION, $DB;

        $connection = Application::getConnection();

        // Скрипт идемпотентен (CREATE TABLE IF NOT EXISTS), поэтому гоняем его всегда,
        // когда не хватает хотя бы одной таблицы: иначе новая таблица из обновления
        // не появится на уже установленном модуле.
        $expectedTables = array(
            'b_mibazarow_bxadmins_chat',
            'b_mibazarow_bxadmins_message',
            'b_mibazarow_bxadmins_index',
            'b_mibazarow_bxadmins_change',
            'b_mibazarow_bxadmins_task',
            'b_mibazarow_bxadmins_task_event',
            'b_mibazarow_bxadmins_llm_profile',
            'b_mibazarow_bxadmins_mcp_server',
            'b_mibazarow_bxadmins_schedule',
            'b_mibazarow_bxadmins_memory',
            'b_mibazarow_bxadmins_todo',
            'b_mibazarow_bxadmins_skill',
            'b_mibazarow_bxadmins_skill_version',
            'b_mibazarow_bxadmins_custom_module',
        );

        $tablesMissing = false;
        foreach ($expectedTables as $table) {
            if (!$connection->isTableExists($table)) {
                $tablesMissing = true;
                break;
            }
        }

        if ($tablesMissing) {
            // Легаси-исключение: D7-аналога для пакетного выполнения SQL-файла установщика нет.
            $errors = $DB->RunSQLBatch(__DIR__ . '/db/mysql/install.sql');
            if ($errors) {
                $APPLICATION->ThrowException(implode('', $errors));
                return false;
            }
        }

        if (!$this->InstallFiles()) {
            return false;
        }

        ModuleManager::registerModule($this->MODULE_ID);

        // Классы модуля грузим штатным автозагрузчиком: до регистрации модуля
        // ядро про namespace Mibazarow\Bxadmins не знает, ручные require_once не нужны.
        if (!Loader::includeModule($this->MODULE_ID)) {
            $this->UnInstallFiles();
            ModuleManager::unRegisterModule($this->MODULE_ID);
            $APPLICATION->ThrowException('BXADMIN-S: не удалось загрузить модуль после регистрации.');
            return false;
        }

        try {
            Config::ensureDefaults();
            // Старое единственное подключение из опций переносим в таблицу профилей.
            LlmProfileService::ensureFromOptions();
            $this->registerGlobalMenuEvent();

            // Легаси-исключение: фоновые агенты Битрикса (CAgent) — штатный механизм
            // планировщика ядра, D7-аналога регистрации агентов нет.
            // Без агента индексация не пойдёт, поэтому сбой регистрации роняет установку.
            if (!IndexService::registerAgent()) {
                throw new \RuntimeException('не удалось зарегистрировать агента индексации');
            }

            $this->registerMaintenanceAgent();
            $this->registerMcpRefreshAgent();
            $this->registerDaemonAgent();
            $this->registerScheduleAgent();
            $this->registerScraperAgent();
        } catch (\Throwable $e) {
            $this->UnInstallFiles();
            ModuleManager::unRegisterModule($this->MODULE_ID);
            $APPLICATION->ThrowException('BXADMIN-S: ' . $e->getMessage());
            return false;
        }

        return true;
    }

    public function DoUninstall()
    {
        global $APPLICATION, $DB;

        $this->unregisterGlobalMenuEvent();

        // Удаляем агенты модуля (см. исключение CAgent выше).
        \CAgent::RemoveModuleAgents($this->MODULE_ID);

        $this->UnInstallFiles();

        // Легаси-исключение: D7-аналога для пакетного выполнения SQL-файла установщика нет.
        $errors = $DB->RunSQLBatch(__DIR__ . '/db/mysql/uninstall.sql');
        if ($errors) {
            $APPLICATION->ThrowException(implode('', $errors));
            return false;
        }

        ModuleManager::unRegisterModule($this->MODULE_ID);

        return true;
    }

    /**
     * Тонкие admin-обёртки: штатная точка входа модуля в /bitrix/admin/.
     * Копирует только файлы модуля и проверяет результат.
     */
    public function InstallFiles(): bool
    {
        global $APPLICATION;

        $from = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . $this->MODULE_ID . '/install/admin';
        $to = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin';

        if (!is_dir($from)) {
            return true;
        }

        // Легаси-исключение: CopyDirFiles — штатный установщик Битрикса,
        // D7-аналога для раскладки admin-файлов модуля нет.
        CopyDirFiles($from, $to, true, true);

        $files = glob($from . '/*.php') ?: array();
        foreach ($files as $file) {
            if (!is_file($to . '/' . basename($file))) {
                $APPLICATION->ThrowException(
                    'BXADMIN-S: не удалось установить admin-обёртку ' . basename($file)
                );
                return false;
            }
        }

        return true;
    }

    /**
     * Удаляет только admin-обёртки, установленные модулем.
     */
    public function UnInstallFiles(): void
    {
        $from = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . $this->MODULE_ID . '/install/admin';
        $to = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin';

        if (!is_dir($from)) {
            return;
        }

        // Легаси-исключение: DeleteDirFiles — штатный установщик Битрикса.
        DeleteDirFiles($from, $to);
    }

    /** Суточный агент чистки базы (без дублей). */
    private function registerMaintenanceAgent(): void
    {
        $existing = \CAgent::GetList(
            array('ID' => 'ASC'),
            array('=NAME' => MaintenanceAgent::CODE, '=MODULE_ID' => $this->MODULE_ID)
        )->Fetch();

        if ($existing) {
            \CAgent::Update((int)$existing['ID'], array(
                'ACTIVE' => 'Y',
                'AGENT_INTERVAL' => MaintenanceAgent::INTERVAL,
                'IS_PERIOD' => 'Y',
            ));

            return;
        }

        \CAgent::AddAgent(
            MaintenanceAgent::CODE,
            $this->MODULE_ID,
            'Y',
            MaintenanceAgent::INTERVAL,
            '',
            'Y',
            '',
            100,
            false,
            false
        );
    }

    /** Обновление каталогов MCP-серверов по TTL. */
    private function registerMcpRefreshAgent(): void
    {
        $existing = \CAgent::GetList(
            array('ID' => 'ASC'),
            array('=NAME' => McpRefreshAgent::CODE, '=MODULE_ID' => $this->MODULE_ID)
        )->Fetch();

        if ($existing) {
            \CAgent::Update((int)$existing['ID'], array(
                'ACTIVE' => 'Y',
                'AGENT_INTERVAL' => McpRefreshAgent::INTERVAL,
                'IS_PERIOD' => 'Y',
            ));

            return;
        }

        \CAgent::AddAgent(
            McpRefreshAgent::CODE,
            $this->MODULE_ID,
            'Y',
            McpRefreshAgent::INTERVAL,
            '',
            'Y',
            '',
            100,
            false,
            false
        );
    }

    /** Сторож Node-демона: поднимает процесс, если он упал. */
    private function registerScraperAgent(): void
    {
        $existing = \CAgent::GetList(
            array('ID' => 'ASC'),
            array('=NAME' => ScraperAgent::CODE, '=MODULE_ID' => $this->MODULE_ID)
        )->Fetch();

        if ($existing) {
            \CAgent::Update((int)$existing['ID'], array(
                'ACTIVE' => 'Y',
                'AGENT_INTERVAL' => ScraperAgent::INTERVAL,
                'IS_PERIOD' => 'Y',
            ));

            return;
        }

        \CAgent::AddAgent(
            ScraperAgent::CODE,
            $this->MODULE_ID,
            'Y',
            ScraperAgent::INTERVAL,
            '',
            'Y',
            '',
            100,
            false,
            false
        );
    }

    private function registerDaemonAgent(): void
    {
        $existing = \CAgent::GetList(
            array('ID' => 'ASC'),
            array('=NAME' => DaemonAgent::CODE, '=MODULE_ID' => $this->MODULE_ID)
        )->Fetch();

        if ($existing) {
            \CAgent::Update((int)$existing['ID'], array(
                'ACTIVE' => 'Y',
                'AGENT_INTERVAL' => DaemonAgent::INTERVAL,
                'IS_PERIOD' => 'Y',
            ));

            return;
        }

        \CAgent::AddAgent(
            DaemonAgent::CODE,
            $this->MODULE_ID,
            'Y',
            DaemonAgent::INTERVAL,
            '',
            'Y',
            '',
            100,
            false,
            false
        );
    }

    /**
     * Раздел BXADMIN-S в верхнем глобальном меню админки.
     * Сначала снимаем старую регистрацию, чтобы не плодить дубликаты обработчика.
     */
    private function registerGlobalMenuEvent(): void
    {
        $eventManager = EventManager::getInstance();

        $eventManager->unRegisterEventHandler(
            'main',
            'OnBuildGlobalMenu',
            $this->MODULE_ID,
            self::GLOBAL_MENU_HANDLER_CLASS,
            self::GLOBAL_MENU_HANDLER_METHOD
        );

        $eventManager->registerEventHandlerCompatible(
            'main',
            'OnBuildGlobalMenu',
            $this->MODULE_ID,
            self::GLOBAL_MENU_HANDLER_CLASS,
            self::GLOBAL_MENU_HANDLER_METHOD
        );
    }

    /** Планировщик задач по расписанию: раз в минуту. */
    private function registerScheduleAgent(): void
    {
        $existing = \CAgent::GetList(
            array('ID' => 'ASC'),
            array('=NAME' => ScheduleAgent::CODE, '=MODULE_ID' => $this->MODULE_ID)
        )->Fetch();

        if ($existing) {
            \CAgent::Update((int)$existing['ID'], array(
                'ACTIVE' => 'Y',
                'AGENT_INTERVAL' => ScheduleAgent::INTERVAL,
                'IS_PERIOD' => 'Y',
            ));

            return;
        }

        \CAgent::AddAgent(
            ScheduleAgent::CODE,
            $this->MODULE_ID,
            'Y',
            ScheduleAgent::INTERVAL,
            '',
            'Y',
            '',
            100,
            false,
            false
        );
    }

    private function unregisterGlobalMenuEvent(): void
    {
        EventManager::getInstance()->unRegisterEventHandler(
            'main',
            'OnBuildGlobalMenu',
            $this->MODULE_ID,
            self::GLOBAL_MENU_HANDLER_CLASS,
            self::GLOBAL_MENU_HANDLER_METHOD
        );
    }
}
