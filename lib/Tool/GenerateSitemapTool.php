<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\Loader;
use Bitrix\Main\SiteTable;
use Bitrix\Seo\Sitemap\Generator;
use Bitrix\Seo\Sitemap\Internals\SitemapTable;

/**
 * Пересборка sitemap штатным генератором модуля seo (b_seo_sitemap).
 * Генератор работает порциями по ~13 секунд; инструмент крутит его до конца
 * или до max_seconds. Файлы восстанавливаются git-точкой, SQL-дамп не нужен.
 */
class GenerateSitemapTool extends AbstractChangeTool
{
    public const DOMAIN = 'seo';

    public static function getName(): string
    {
        return 'generate_sitemap';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'generate_sitemap',
            'description' => 'Пересобирает sitemap.xml и sitemap-iblock-*.xml через модуль seo (настройки карты сайта). Работает порциями до max_seconds; если не успел — вызови ещё раз. Файлы откатываются git-точкой.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'sitemap_id' => array('type' => 'integer', 'description' => 'ID настройки карты сайта из b_seo_sitemap; по умолчанию активная для сайта'),
                    'max_seconds' => array('type' => 'integer', 'description' => 'Сколько секунд работать, по умолчанию 60, максимум 180'),
                ),
            ),
        );
    }

    public static function needsSqlDump(): bool
    {
        // Меняются только XML-файлы и дата запуска карты.
        return false;
    }

    public static function rollbackNeedsRestore(): bool
    {
        return true;
    }

    public static function preview(array $args): array
    {
        if (!Loader::includeModule('seo')) {
            throw new \RuntimeException('Модуль seo недоступен: штатный генератор sitemap не установлен.');
        }

        $sitemap = self::sitemap($args);
        $maxSeconds = max(10, min(180, (int)($args['max_seconds'] ?? 60)));
        $files = glob(self::root() . '/sitemap*.xml') ?: array();
        $lastRun = $sitemap['DATE_RUN'] instanceof \Bitrix\Main\Type\DateTime
            ? $sitemap['DATE_RUN']->toString()
            : (string)($sitemap['DATE_RUN'] ?? '');

        return array(
            'summary' => 'Пересборка sitemap #' . $sitemap['ID'] . ' «' . $sitemap['NAME'] . '»',
            'payload' => array(
                'args' => array('sitemap_id' => (int)$sitemap['ID'], 'max_seconds' => $maxSeconds),
                'before' => array('date_run' => $lastRun, 'files' => count($files)),
            ),
            'preview' => 'Карта сайта #' . $sitemap['ID'] . ' «' . $sitemap['NAME'] . '» (сайт ' . $sitemap['SITE_ID'] . ').'
                . "\nПоследний запуск: " . ($lastRun !== '' ? $lastRun : 'не было')
                . '; XML-файлов сейчас: ' . count($files) . '.'
                . "\nЛимит работы: " . $maxSeconds . ' с.',
        );
    }

    public static function apply(array $payload): array
    {
        $args = (array)($payload['args'] ?? array());
        $sitemapId = (int)($args['sitemap_id'] ?? 0);
        $maxSeconds = max(10, min(180, (int)($args['max_seconds'] ?? 60)));

        if (!Loader::includeModule('seo') || $sitemapId <= 0) {
            throw new \InvalidArgumentException('Не найдена настройка карты сайта для пересборки.');
        }

        $before = SitemapTable::getById($sitemapId)->fetch();
        if (!$before) {
            throw new \InvalidArgumentException('Карта сайта #' . $sitemapId . ' не найдена.');
        }

        $beforeRun = self::dateRun($before);
        $deadline = time() + $maxSeconds;
        $steps = 0;
        $complete = false;
        $status = '';

        $generator = new Generator($sitemapId);

        do {
            $ok = $generator->run();
            $status = (string)$generator->getStatusMessage();
            $steps++;

            if (!$ok) {
                throw new \RuntimeException('Генератор sitemap вернул ошибку: ' . ($status !== '' ? $status : 'без описания'));
            }

            $row = SitemapTable::getById($sitemapId)->fetch();
            $afterRun = $row ? self::dateRun($row) : '';

            if ($afterRun !== '' && $afterRun !== $beforeRun) {
                $complete = true;
                break;
            }
        } while ($steps < 200 && time() + 1 < $deadline);

        $row = SitemapTable::getById($sitemapId)->fetch();
        $files = glob(self::root() . '/sitemap*.xml') ?: array();

        return array(
            'sitemap_id' => $sitemapId,
            'steps' => $steps,
            'complete' => $complete,
            'status' => $status,
            'date_run' => $row ? self::dateRun($row) : '',
            'files' => count($files),
            'note' => $complete
                ? 'Sitemap пересобран.'
                : 'Генератор не успел за ' . $maxSeconds . ' с — вызови инструмент ещё раз, он продолжит с текущего шага.',
        );
    }

    public static function rollback(array $payload): void
    {
        // Откат выполняет ChangeService через git-точку (rollbackNeedsRestore).
    }

    /** @return array<string,mixed> */
    private static function sitemap(array $args): array
    {
        $sitemapId = (int)($args['sitemap_id'] ?? 0);

        if ($sitemapId > 0) {
            $row = SitemapTable::getById($sitemapId)->fetch();
            if (!$row) {
                throw new \InvalidArgumentException('Карта сайта #' . $sitemapId . ' не найдена.');
            }

            return $row;
        }

        $siteId = '';
        $site = SiteTable::getList(array(
            'select' => array('LID'),
            'filter' => array('=DEF' => 'Y', '=ACTIVE' => 'Y'),
            'limit' => 1,
        ))->fetch();

        if ($site) {
            $siteId = (string)$site['LID'];
        }

        $filter = array('=ACTIVE' => 'Y');
        if ($siteId !== '') {
            $filter['=SITE_ID'] = $siteId;
        }

        $row = SitemapTable::getList(array(
            'select' => array('ID', 'SITE_ID', 'NAME', 'ACTIVE', 'DATE_RUN'),
            'filter' => $filter,
            'order' => array('ID' => 'DESC'),
            'limit' => 1,
        ))->fetch();

        if (!$row) {
            throw new \InvalidArgumentException('Нет активной настройки карты сайта (b_seo_sitemap). Создай её в SEO → Карта сайта.');
        }

        return $row;
    }

    private static function dateRun(array $row): string
    {
        $value = $row['DATE_RUN'] ?? null;

        if ($value instanceof \Bitrix\Main\Type\DateTime) {
            return $value->toString();
        }

        return (string)$value;
    }

    private static function root(): string
    {
        return rtrim((string)Loader::getDocumentRoot(), '/');
    }
}
