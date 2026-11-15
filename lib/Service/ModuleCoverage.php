<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\ModuleManager;

/**
 * Покрытие модулей инструментами: что уже управляется, чего не хватает и почему.
 *
 * Карта ведётся вручную: связь «модуль → инструмент» автоматически не вывести,
 * а честный список важнее красивой эвристики. Модули без инструментов попадают
 * в отчёт вместе с причиной — чтобы агент мог объяснить, а не отмолчаться.
 */
final class ModuleCoverage
{
    /** Модуль => инструменты, которые им управляют. */
    private const MAP = array(
        'main' => array(
            'site_info', 'list_sites', 'get_site', 'update_site', 'create_site',
            'list_users', 'create_user', 'update_user', 'delete_user',
            'set_user_groups', 'set_user_password',
            'list_groups', 'create_group', 'update_group',
            'list_mail_templates', 'list_mail_events', 'save_mail_template',
            'get_module_options', 'set_module_option',
            'list_modules', 'module_info', 'save_memory', 'list_memories', 'forget_memory', 'save_skill', 'list_skills', 'delete_skill', 'run_php', 'install_module', 'uninstall_module', 'download_module',
            'create_module', 'delete_module',
            'module_coverage',
            'list_templates', 'list_events', 'add_event_handler', 'remove_event_handler',
            'clear_cache', 'list_agents', 'create_agent', 'delete_agent',
            'get_entity_fields', 'run_sql', 'get_server_status', 'send_test_email', 'create_user_field',
            'check_uptime', 'upload_backup_s3', 'create_site_template',
        ),
        'iblock' => array(
            'list_iblocks', 'create_iblock', 'create_iblock_type', 'update_iblock', 'delete_iblock',
            'list_iblock_sections', 'create_iblock_section', 'update_iblock_section', 'delete_iblock_section',
            'list_iblock_elements', 'get_iblock_element', 'create_iblock_element', 'update_iblock_element', 'delete_iblock_element',
            'list_iblock_properties', 'create_iblock_property', 'update_iblock_property', 'delete_iblock_property',
            'get_iblock_fields', 'set_element_properties', 'set_element_seo', 'set_element_images', 'search_images', 'list_elements_without_images', 'analyze_image',
            'import_csv_elements', 'bulk_update_elements',
        ),
        'catalog' => array(
            'list_catalog_products', 'list_price_types', 'list_stores', 'list_offers',
            'get_product_prices', 'get_store_stock', 'update_product_price',
            'update_catalog_product', 'set_store_stock', 'create_offer', 'update_offer',
            'bulk_update_prices',
        ),
        'sale' => array(
            'list_orders', 'create_order', 'update_order', 'update_order_status', 'list_order_statuses',
            'list_pay_systems', 'list_deliveries', 'list_discounts', 'create_discount', 'update_discount',
            'delete_discount', 'list_coupons', 'create_coupon', 'delete_coupon',
            'list_currencies', 'list_locations',
            'update_order_items', 'create_delivery', 'create_pay_system',
        ),
        'forum' => array(
            'list_forums', 'create_forum', 'update_forum', 'delete_forum',
            'list_forum_topics', 'create_forum_topic', 'update_forum_topic', 'delete_forum_topic',
            'list_forum_posts', 'create_forum_post', 'update_forum_post', 'delete_forum_post',
        ),
        'blog' => array(
            'list_blog_posts', 'create_blog_post', 'update_blog_post', 'delete_blog_post',
            'list_blog_comments', 'add_blog_comment', 'delete_blog_comment',
        ),
        'socialnetwork' => array('list_social_groups'),
        'calendar' => array(
            'list_calendar_events', 'create_calendar_event', 'update_calendar_event', 'delete_calendar_event',
        ),
        'form' => array('list_forms', 'get_form_results', 'create_web_form', 'update_web_form'),
        'subscribe' => array('list_subscriptions', 'list_subscribe_rubrics', 'add_subscriber'),
        'bizproc' => array('list_bizproc_templates'),
        'rest' => array('list_rest_apps'),
        'seo' => array(
            'check_seo', 'get_robots', 'set_robots',
            'list_redirects', 'set_redirect', 'delete_redirect', 'reindex_search', 'check_links',
        ),
        'search' => array('search_knowledge', 'reindex_search'),
        'fileman' => array(
            'read_file', 'write_site_file', 'delete_site_file', 'write_local_file', 'delete_local_file',
            'upload_file', 'list_media_files', 'delete_media_file',
        ),
        'highloadblock' => array('list_highload_blocks', 'create_highload_block', 'delete_highload_block'),
        'currency' => array('list_currencies'),
        'location' => array('list_locations'),
        'mail' => array('list_mail_templates', 'list_mail_events', 'save_mail_template'),
        'advertising' => array('list_banners'),
        'support' => array('list_tickets'),
        'vote' => array('list_votes'),
        'learning' => array('list_courses'),
        'landing' => array('list_landings'),
        'statistic' => array('get_traffic_summary'),
        'lists' => array('list_module_content'),
        'wiki' => array('list_module_content'),
        'photogallery' => array('list_module_content'),
    );

    /**
     * Модули, которые осознанно не покрыты инструментами, и почему.
     * Отчёт показывает их отдельно — это не пробел, а решение.
     */
    private const REASONS = array(
        'pull' => 'Не используется: события доставляются своим SSE-потоком; управлять нечем.',
        'ui' => 'Библиотека интерфейса ядра — не пользовательская сущность.',
        'im' => 'Чаты сотрудников: переписка не входит в управление сайтом и трогать её без нужды нельзя.',
        'sender' => 'Массовые рассылки запрещены политикой харнеса.',
        'workflow' => 'Устаревший документооборот, вытеснен bizproc.',
        'webservice' => 'Устаревший SOAP-слой, в новых установках не используется.',
        'mobileapp' => 'Сборка мобильного приложения — вне управления сайтом.',
        'clouds' => 'Подключение облачных хранилищ настраивается в админке ядра.',
        'cluster' => 'Кластер и репликация БД — инфраструктура сервера, требует root.',
        'perfmon' => 'Монитор производительности — диагностика ядра, не задача владельца.',
        'messageservice' => 'Провайдеры SMS/мессенджеров настраиваются в админке.',
        'security' => 'Проактивная защита настраивается в своём разделе ядра; смена правил без человека опасна.',
        'translate' => 'Перевод языковых файлов — работа разработчика, а не владельца сайта.',
        'socialservices' => 'Интеграции соцсетей настраиваются в админке.',
        'ldap' => 'Корпоративная авторизация — инфраструктура сервера.',
        'b24connector' => 'Связка с Битрикс24 настраивается мастером подключения.',
        'bitrixcloud' => 'Облачные сервисы Битрикс — подписка и лицензия.',
        'storeassist' => 'Служебный модуль магазина.',
        'conversion' => 'Аналитика конверсии — отчёты ядра.',
        'report' => 'Конструктор отчётов — интерфейс администратора.',
        'abtest' => 'A/B-тесты настраиваются в интерфейсе ядра.',
        'idea' => 'Идеи пользователей — редко нужны владельцу, управление в своём разделе.',
        'bizprocdesigner' => 'Визуальный конструктор бизнес-процессов — только интерфейс.',
        'bitrix.eshop' => 'Демо-контент магазина, не рабочий модуль.',
        'bitrix.sitecorporate' => 'Демо-контент корпоративного сайта.',
        'mibazarow.bxadmins' => 'Сам харнес: своими инструментами себя не администрирует.',
    );

    /**
     * Отчёт: по каждому установленному модулю — есть ли инструменты.
     *
     * @return array{installed:int,covered:int,not_covered_count:int,not_covered:array,modules:array}
     */
    public static function report(): array
    {
        $installed = self::installedModules();
        $modules = array();
        $notCovered = array();
        $covered = 0;

        foreach ($installed as $moduleId) {
            $tools = array_values(array_filter(
                self::MAP[$moduleId] ?? array(),
                static fn (string $name): bool => self::toolExists($name)
            ));

            $reason = self::REASONS[$moduleId] ?? null;
            $owned = CustomModuleService::isOwned($moduleId);

            if ($tools !== array()) {
                $covered++;
                $status = 'covered';
            } elseif ($owned) {
                // Модуль написан агентом: отдельные инструменты ему не нужны —
                // API доступно через run_php, файлы — через write_local_file.
                $status = 'own';
                $reason = 'Собственный модуль агента: управляется своим кодом через run_php и файловые инструменты.';
            } elseif ($reason !== null) {
                $status = 'intentional';
                $notCovered[] = array('module' => $moduleId, 'reason' => $reason);
            } else {
                $status = 'missing';
                $notCovered[] = array('module' => $moduleId, 'reason' => 'Инструментов нет — можно добавить.');
            }

            $modules[$moduleId] = array(
                'module' => $moduleId,
                'status' => $status,
                'tools' => $tools,
                'count' => count($tools),
                'reason' => $reason,
            );
        }

        return array(
            'installed' => count($installed),
            'covered' => $covered,
            'not_covered_count' => count($notCovered),
            'not_covered' => $notCovered,
            'modules' => array_values($modules),
        );
    }

    /** @return string[] активные модули установки */
    private static function installedModules(): array
    {
        $modules = array();

        foreach (ModuleManager::getInstalledModules() as $module) {
            $modules[] = (string)$module['ID'];
        }

        sort($modules);

        return $modules;
    }

    private static function toolExists(string $name): bool
    {
        foreach (ToolRegistry::tools() as $tool) {
            if ($tool::getName() === $name) {
                return true;
            }
        }

        return false;
    }
}
