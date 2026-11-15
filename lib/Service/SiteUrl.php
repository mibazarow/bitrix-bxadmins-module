<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\SiteTable;

/**
 * Домен сайта, которому принадлежит модуль.
 *
 * На установке несколько сайтов на общей базе, поэтому нельзя брать первый
 * попавшийся: определяем по HTTP-хосту, а в CLI — по имени каталога сайта.
 */
final class SiteUrl
{
    public static function current(): string
    {
        $host = (string)Context::getCurrent()->getRequest()->getHttpHost();

        if ($host !== '') {
            return 'https://' . $host;
        }

        // В CLI: имя каталога сайта совпадает с SERVER_NAME.
        $dirName = basename(rtrim(Application::getDocumentRoot(), '/'));

        $site = SiteTable::getList(array(
            'select' => array('SERVER_NAME'),
            'filter' => array('=SERVER_NAME' => $dirName, '=ACTIVE' => 'Y'),
            'limit' => 1,
        ))->fetch();

        if ($site && (string)$site['SERVER_NAME'] !== '') {
            return 'https://' . (string)$site['SERVER_NAME'];
        }

        // Последний вариант — сайт по умолчанию.
        $site = SiteTable::getList(array(
            'select' => array('SERVER_NAME'),
            'filter' => array('=ACTIVE' => 'Y'),
            'order' => array('DEF' => 'DESC', 'SORT' => 'ASC'),
            'limit' => 1,
        ))->fetch();

        $name = (string)($site['SERVER_NAME'] ?? '');

        return $name !== '' ? 'https://' . $name : '';
    }

    /** Приводит путь к абсолютному URL текущего сайта. */
    public static function absolute(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $base = self::current();

        return $base !== '' ? $base . '/' . ltrim($url, '/') : '';
    }
}
