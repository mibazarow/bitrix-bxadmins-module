<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

/**
 * Проверка внешних ссылок: только публичный http(s).
 *
 * Защищает от SSRF: агент не должен тянуть данные из localhost, частных
 * подсетей и служебных адресов облака. Используется загрузкой картинок и
 * браузерными инструментами.
 */
final class UrlGuard
{
    private const PORTS = array(80, 443);

    /**
     * @param int[]|null $ports допустимые порты; null — любые (только для проверки схемы)
     *
     * @throws \InvalidArgumentException
     */
    public static function assertPublicHttp(string $url, ?array $ports = null): void
    {
        $parts = parse_url(trim($url));

        if (!is_array($parts)) {
            throw new \InvalidArgumentException('Некорректная ссылка.');
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, array('http', 'https'), true)) {
            throw new \InvalidArgumentException('Разрешены только ссылки http и https.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Ссылки с логином и паролем не поддерживаются.');
        }

        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        if ($host === '') {
            throw new \InvalidArgumentException('В ссылке нет домена.');
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new \InvalidArgumentException('Внутренние адреса недоступны.');
        }

        if ($ports !== null) {
            $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
            if (!in_array($port, $ports, true)) {
                throw new \InvalidArgumentException('Порт ' . $port . ' не разрешён.');
            }
        }

        foreach (self::resolve($host) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \InvalidArgumentException('Ссылки на внутренние адреса запрещены.');
            }
        }
    }

    /** Мягкая проверка: false вместо исключения. */
    public static function isPublicHttp(string $url, ?array $ports = self::PORTS): bool
    {
        try {
            self::assertPublicHttp($url, $ports);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return string[] IP-адреса хоста (IPv4 и IPv6). */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return array($host);
        }

        $ips = array();

        foreach ((array)@gethostbynamel($host) as $ip) {
            $ips[] = (string)$ip;
        }

        foreach ((array)@dns_get_record($host, DNS_AAAA) as $record) {
            if (!empty($record['ipv6'])) {
                $ips[] = (string)$record['ipv6'];
            }
        }

        if ($ips === array()) {
            throw new \InvalidArgumentException('Не удалось определить адрес сайта по ссылке.');
        }

        return $ips;
    }
}
