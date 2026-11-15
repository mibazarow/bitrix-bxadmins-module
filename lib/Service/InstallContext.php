<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Context;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\SiteTable;
use Mibazarow\Bxadmins\Config;

/**
 * Адаптивный контекст установки для системного промпта.
 *
 * Модуль может стоять на любом сайте Битрикса: здесь собираются факты именно
 * этой установки — сайт, версии, модули, включённые домены, состояние индекса,
 * лимиты, режим задачи. Секреты (ключи API, пароли) сюда не попадают.
 */
final class InstallContext
{
    /** Модули, которые влияют на доступные инструменты. */
    private const MODULES = array(
        'iblock', 'catalog', 'sale', 'forum', 'blog', 'calendar', 'form',
        'landing', 'subscribe', 'highloadblock', 'vote', 'learning',
    );

    /** @var string|null кэш на время запроса */
    private static $block = null;

    /**
     * @param array{origin?:string,bypass?:bool,user_id?:int,chat_title?:string} $task
     */
    public static function build(array $task = array()): string
    {
        $signature = json_encode($task, JSON_UNESCAPED_UNICODE);

        if (self::$block !== null && self::$block['signature'] === $signature) {
            return self::$block['text'];
        }

        $text = implode("\n", self::lines($task));
        self::$block = array('signature' => $signature, 'text' => $text);

        return $text;
    }

    private static function lines(array $task): array
    {
        $sites = self::sites();
        $current = self::currentSite($sites);
        $lines = array();

        $lines[] = 'Assistant: ' . self::brand() . '.';

        $lines[] = 'Site: ' . self::siteLabel($current)
            . ($sites !== array() ? '; installation sites: ' . self::siteList($sites, $current) : '') . '.';

        $lines[] = 'Bitrix: ' . (defined('SM_VERSION') ? (string)SM_VERSION : 'unknown version')
            . '; PHP ' . PHP_VERSION
            . '; BXADMIN-S module ' . self::moduleVersion() . '.';

        $installed = self::installedModules();
        $lines[] = 'Modules: ' . ($installed !== array() ? implode(', ', $installed) : 'none of the relevant modules installed') . '.';

        $lines[] = self::domainsLine();
        $lines[] = self::indexLine();
        $lines[] = 'Model: ' . (Config::llmModel() !== '' ? Config::llmModel() : 'not configured')
            . '; tasks per user: ' . TaskService::USER_TASK_LIMIT
            . '; parallel workers: ' . Config::workerConcurrency() . '.';

        $lines[] = self::backupsLine();

        $user = self::user($task);
        if ($user !== '') {
            $lines[] = 'User: ' . $user . '.';
        }

        $lines[] = 'Now: ' . date('Y-m-d H:i') . ' (' . date_default_timezone_get() . ').';

        $origin = (string)($task['origin'] ?? 'chat');
        if ($origin === 'schedule') {
            $lines[] = 'Run: scheduled task. No user in chat; nobody can confirm a card.';
        } else {
            $lines[] = 'Run: normal chat; the user is present and can press "Confirm".';
        }

        if (!empty($task['bypass'])) {
            $lines[] = 'Bypass mode is on: changes are applied immediately after the SQL dump.';
        }

        return $lines;
    }

    /** Имя ассистента фиксировано: это название модуля. */
    private static function brand(): string
    {
        return 'bxadmin';
    }

    /** @return array<int,array> */
    private static function sites(): array
    {
        try {
            return SiteTable::getList(array(
                'select' => array('LID', 'NAME', 'SERVER_NAME', 'DEF', 'SORT'),
                'filter' => array('=ACTIVE' => 'Y'),
                'order' => array('DEF' => 'DESC', 'SORT' => 'ASC'),
                'limit' => 6,
            ))->fetchAll();
        } catch (\Throwable $e) {
            return array();
        }
    }

    /**
     * Текущий сайт — по домену админки/демона, а не по SITE_ID: в cron и демоне
     * SITE_ID часто указывает на сайт по умолчанию, а админка живёт на другом.
     */
    private static function currentSite(array $sites): array
    {
        $host = (string)parse_url(Config::daemonSiteUrl(), PHP_URL_HOST);
        if ($host === '') {
            $host = (string)Context::getCurrent()->getRequest()->getHttpHost();
        }
        $host = strtolower(preg_replace('/:\d+$/', '', $host));

        foreach ($sites as $site) {
            if ($host !== '' && strtolower((string)$site['SERVER_NAME']) === $host) {
                return $site;
            }
        }

        foreach ($sites as $site) {
            if ((string)($site['DEF'] ?? 'N') === 'Y') {
                return $site;
            }
        }

        return $sites[0] ?? array();
    }

    private static function siteLabel(array $site): string
    {
        if ($site === array()) {
            return 'not detected';
        }

        return $site['LID'] . ' — "' . $site['NAME'] . '" (' . $site['SERVER_NAME'] . ')';
    }

    private static function siteList(array $sites, array $current): string
    {
        $labels = array();
        foreach ($sites as $site) {
            $labels[] = $site['LID'] . ($site['LID'] === ($current['LID'] ?? '') ? ' (current)' : '');
        }

        return implode(', ', $labels);
    }

    private static function moduleVersion(): string
    {
        try {
            $version = (string)ModuleManager::getVersion(Config::MODULE_ID);

            return $version !== '' ? $version : 'unknown version';
        } catch (\Throwable $e) {
            return 'unknown version';
        }
    }

    /** @return string[] */
    private static function installedModules(): array
    {
        $installed = array();
        foreach (self::MODULES as $moduleId) {
            try {
                if (ModuleManager::isModuleInstalled($moduleId)) {
                    $installed[] = $moduleId;
                }
            } catch (\Throwable $e) {
                // проверка модуля не должна ломать промпт
            }
        }

        return $installed;
    }

    private static function domainsLine(): string
    {
        $labels = array(
            'core' => 'Core',
            'content' => 'Content',
            'seo' => 'SEO',
            'commerce' => 'Commerce',
            'collaboration' => 'Collaboration',
            'automation' => 'Automation',
            'marketing' => 'Marketing',
            'integrations' => 'Integrations',
            'security' => 'Security',
            'events' => 'Events',
            'design' => 'Design',
        );

        $enabled = array();
        $disabled = array();

        foreach (Config::DOMAINS as $key => $label) {
            if (Config::isDomainAllowed($key)) {
                $enabled[] = $labels[$key] ?? (string)$label;
            } else {
                $disabled[] = $labels[$key] ?? (string)$label;
            }
        }

        $line = 'Domains enabled: ' . ($enabled !== array() ? implode(', ', $enabled) : 'none') . '.';
        if ($disabled !== array()) {
            $line .= ' Disabled: ' . implode(', ', $disabled)
                . ' — their tools are absent; do not look for a workaround, offer to enable them in settings.';
        }

        return $line;
    }

    private static function indexLine(): string
    {
        try {
            $status = IndexService::status();
            $ready = !empty($status['complete']);

            return 'Knowledge base: ' . ($ready ? 'ready' : 'indexing, ' . (int)($status['progress_percent'] ?? 0) . '% done')
                . ', fragments ' . (int)($status['rows'] ?? 0) . '.';
        } catch (\Throwable $e) {
            return 'Knowledge base: status unknown.';
        }
    }

    private static function backupsLine(): string
    {
        try {
            $count = count(BackupService::list());
        } catch (\Throwable $e) {
            $count = 0;
        }

        $s3 = trim(Config::get('backup_s3_bucket')) !== '' && trim(Config::get('backup_s3_key')) !== '';

        return 'Backups: ' . $count . ' archive(s), S3 ' . ($s3 ? 'configured' : 'not configured') . '.';
    }

    private static function user(array $task): string
    {
        $userId = (int)($task['user_id'] ?? 0);
        if ($userId <= 0) {
            return '';
        }

        try {
            $row = \CUser::GetByID($userId)->Fetch();
        } catch (\Throwable $e) {
            return '';
        }

        if (!$row) {
            return '';
        }

        $name = trim((string)$row['NAME'] . ' ' . (string)$row['LAST_NAME']);
        if ($name === '') {
            $name = (string)$row['LOGIN'];
        }

        return $name . ' (administrator, full module rights)';
    }
}
