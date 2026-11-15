<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Mibazarow\Bxadmins\Service\LlmProfileService;

/**
 * Настройки модуля: значения по умолчанию, чтение/запись опций Битрикса, домены прав.
 */
class Config
{
    public const MODULE_ID = 'mibazarow.bxadmins';

    public const DEFAULTS = array(
        'llm_endpoint' => '',
        'llm_api_key' => '',
        'llm_model' => '',
        'llm_reasoning' => 'low',
        'embedding_endpoint' => 'http://127.0.0.1:8000',
        'embedding_model' => 'google/embeddinggemma-2',
        'log_retention_days' => '15',
        'log_dir' => '',
        'retention_days' => '7',
        'context_window' => '240000',
        'worker_concurrency' => '10',
        'backup_s3_endpoint' => '',
        'backup_s3_bucket' => '',
        'backup_s3_key' => '',
        'backup_s3_secret' => '',
        'backup_s3_region' => 'us-east-1',
        'worker_secret' => '',
        'backup_keep' => '5',
        'sql_dump_policy' => 'irreversible',
        'index_search_max_rows' => '5000',
        'schema_migrations' => '',
        'index_complete' => 'N',
        'allowed_domains' => '',
    );

    /** Домены прав: ключ => подпись в настройках. */
    public const DOMAINS = array(
        'core' => 'Ядро',
        'content' => 'Контент',
        'seo' => 'SEO и адреса',
        'commerce' => 'Коммерция',
        'collaboration' => 'Совместная работа',
        'automation' => 'Автоматизация',
        'marketing' => 'Маркетинг и аналитика',
        'integrations' => 'Интеграции и интерфейсы',
        'security' => 'Безопасность и инфраструктура',
        'events' => 'Событийная шина',
        'design' => 'Дизайн и шаблоны',
    );

    public const REASONING_LEVELS = array('none', 'low', 'medium', 'high');

    /** Значение опции модуля; неизвестное имя даёт пустую строку. */
    public static function get(string $name): string
    {
        return (string)Option::get(self::MODULE_ID, $name, self::DEFAULTS[$name] ?? '');
    }

    /** Сохраняет только известные ключи DEFAULTS, не-скаляры игнорируются. */
    public static function setMany(array $values): void
    {
        foreach ($values as $name => $value) {
            if (array_key_exists($name, self::DEFAULTS) && is_scalar($value)) {
                Option::set(self::MODULE_ID, $name, (string)$value);
            }
        }
    }

    /** Заполняет незаданные опции значениями по умолчанию (при установке). */
    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $name => $defaultValue) {
            if (Option::get(self::MODULE_ID, $name, null) === null) {
                Option::set(self::MODULE_ID, $name, $defaultValue);
            }
        }
    }

    // Подключения живут в таблице профилей; опции ниже — только для перехода со старой версии.
    public static function llmEndpoint(): string
    {
        $profile = LlmProfileService::active();

        return $profile !== null ? (string)$profile['endpoint'] : self::get('llm_endpoint');
    }

    public static function llmApiKey(): string
    {
        $profile = LlmProfileService::active();

        return $profile !== null ? (string)$profile['api_key'] : self::get('llm_api_key');
    }

    public static function llmModel(): string
    {
        $profile = LlmProfileService::active();

        return $profile !== null ? (string)$profile['model'] : self::get('llm_model');
    }

    public static function llmReasoning(): string
    {
        $profile = LlmProfileService::active();

        return $profile !== null ? (string)$profile['reasoning'] : self::get('llm_reasoning');
    }

    /** ID активного подключения (0 — подключений нет). */
    public static function llmProfileId(): int
    {
        $profile = LlmProfileService::active();

        return $profile !== null ? (int)$profile['id'] : 0;
    }

    /**
     * Подключение к модели настроено: заданы endpoint и модель.
     * Без этого модуль бесполезен, поэтому все функции блокируются.
     */
    public static function isLlmConfigured(): bool
    {
        return trim(self::llmEndpoint()) !== '' && trim(self::llmModel()) !== '';
    }

    public static function embeddingEndpoint(): string
    {
        return self::get('embedding_endpoint');
    }

    public static function embeddingModel(): string
    {
        return self::get('embedding_model');
    }

    public static function logRetentionDays(): int
    {
        return max(0, (int)self::get('log_retention_days'));
    }

    /**
     * Каталог журналов — вне docroot, чтобы логи не были доступны по HTTP
     * и не попадали в синхронизацию проекта.
     */
    public static function logDir(): string
    {
        $configured = trim(self::get('log_dir'));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return dirname(dirname(Application::getDocumentRoot())) . '/bxadmins-data/logs';
    }

    /** Сколько дней хранить завершённые изменения, задачи и события. */
    public static function retentionDays(): int
    {
        return max(1, (int)self::get('retention_days'));
    }

    /**
     * Сколько задач демон выполняет одновременно. Больше двух-трёх смысла нет:
     * каждая задача держит соединение с моделью и иногда браузер.
     */
    public static function workerConcurrency(): int
    {
        return max(1, min(10, (int)self::get('worker_concurrency')));
    }

    /**
     * Сайт, с которым работает демон.
     *
     * Cron Битрикса общий для установки и запускается из каталога основного сайта,
     * поэтому SiteUrl::current() может вернуть чужой домен. Админка модуля живёт
     * на своём сайте — его и запоминаем в опции при первом запуске.
     */
    public static function daemonSiteUrl(): string
    {
        $configured = trim(self::get('daemon_site_url'));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $current = rtrim(\Mibazarow\Bxadmins\Service\SiteUrl::current(), '/');
        $host = (string)Context::getCurrent()->getRequest()->getHttpHost();

        // Запрос из админки: это и есть сайт модуля — запоминаем, чтобы cron
        // из чужого docroot больше не подставлял другой домен.
        if ($host !== '' && $current !== '') {
            self::setDaemonSiteUrl($current);
        }

        return $current;
    }

    public static function setDaemonSiteUrl(string $url): void
    {
        $url = rtrim(trim($url), '/');

        if ($url !== '') {
            Option::set(self::MODULE_ID, 'daemon_site_url', $url);
        }
    }

    /**
     * Общий секрет для внутреннего API демона. Генерируется при установке.
     */
    public static function workerSecret(): string
    {
        $secret = trim(self::get('worker_secret'));
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            Option::set(self::MODULE_ID, 'worker_secret', $secret);
        }

        return $secret;
    }

    /** Максимальное контекстное окно модели в токенах (для расчёта компактации). */
    public static function contextWindow(): int
    {
        $profile = LlmProfileService::active();

        return max(1000, $profile !== null ? (int)$profile['context_window'] : (int)self::get('context_window'));
    }

    public static function backupKeep(): int
    {
        return max(0, (int)self::get('backup_keep'));
    }

    /**
     * Когда создавать полный SQL-дамп перед изменением.
     *
     * irreversible — только для разрушительных операций (по умолчанию): обратимые
     *   изменения откатываются своим rollback(), а файлы — git-точкой.
     * always — как раньше, для любого изменяющего инструмента.
     * off — никогда (аварийный режим; restore_site останется без базы).
     */
    public static function indexSearchMaxRows(): int
    {
        return max(1000, min(50000, (int)self::get('index_search_max_rows')));
    }

    public static function sqlDumpPolicy(): string
    {
        $policy = strtolower(trim(self::get('sql_dump_policy')));

        return in_array($policy, array('irreversible', 'always', 'off'), true) ? $policy : 'irreversible';
    }

    /**
     * Разрешённые домены: сохранённый JSON поверх значений по умолчанию (все включены).
     * Неизвестные ключи игнорируются.
     *
     * @return array<string,bool>
     */
    public static function allowedDomains(): array
    {
        $stored = json_decode(self::get('allowed_domains'), true);
        if (!is_array($stored)) {
            $stored = array();
        }

        $allowed = array();
        foreach (self::DOMAINS as $key => $label) {
            if (array_key_exists($key, $stored)) {
                $allowed[$key] = self::toBool($stored[$key]);
            } else {
                $allowed[$key] = true;
            }
        }

        return $allowed;
    }

    public static function isDomainAllowed(string $domain): bool
    {
        $allowed = self::allowedDomains();

        return isset($allowed[$domain]) && $allowed[$domain] === true;
    }

    /**
     * Сохраняет только известные домены в виде JSON {ключ: bool}.
     */
    public static function setAllowedDomains(array $domains): void
    {
        $normalized = array();
        foreach (self::DOMAINS as $key => $label) {
            $normalized[$key] = self::toBool($domains[$key] ?? false);
        }

        Option::set(self::MODULE_ID, 'allowed_domains', (string)json_encode($normalized));
    }

    private static function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        $value = strtoupper(trim((string)$value));

        return in_array($value, array('1', 'Y', 'TRUE', 'ON', 'YES'), true);
    }
}
