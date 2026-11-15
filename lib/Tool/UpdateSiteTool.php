<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Tool;

use Bitrix\Main\SiteTable;

/** Настройки сайта: название, домен, email, сортировка, активность. */
class UpdateSiteTool extends AbstractChangeTool
{
    public const DOMAIN = 'core';

    private const FIELDS = array(
        'name' => 'SITE_NAME',
        'server_name' => 'SERVER_NAME',
        'email' => 'EMAIL',
        'sort' => 'SORT',
        'active' => 'ACTIVE',
    );

    public static function getName(): string
    {
        return 'update_site';
    }

    public static function getDefinition(): array
    {
        return array(
            'name' => 'update_site',
            'description' => 'Меняет настройки сайта: название, домен, email, сортировку, активность.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'lid' => array('type' => 'string', 'description' => 'Код сайта, например S2'),
                    'name' => array('type' => 'string', 'description' => 'Название сайта'),
                    'server_name' => array('type' => 'string', 'description' => 'Домен без протокола'),
                    'email' => array('type' => 'string', 'description' => 'Email сайта'),
                    'sort' => array('type' => 'integer', 'description' => 'Сортировка'),
                    'active' => array('type' => 'boolean', 'description' => 'Активность'),
                ),
                'required' => array('lid'),
            ),
        );
    }

    public static function preview(array $args): array
    {
        $lid = trim((string)($args['lid'] ?? ''));
        if ($lid === '') {
            throw new \InvalidArgumentException('Параметр lid обязателен.');
        }

        $site = SiteTable::getList(array(
            'select' => array('LID', 'SITE_NAME', 'SERVER_NAME', 'EMAIL', 'SORT', 'ACTIVE'),
            'filter' => array('=LID' => $lid),
            'limit' => 1,
        ))->fetch();

        if (!$site) {
            throw new \InvalidArgumentException('Сайт ' . $lid . ' не найден.');
        }

        $fields = array();

        if (array_key_exists('name', $args)) {
            $fields['SITE_NAME'] = mb_substr(trim((string)$args['name']), 0, 255);
        }
        if (array_key_exists('server_name', $args)) {
            $domain = trim((string)$args['server_name']);
            if ($domain === '' || preg_match('#^[a-z0-9.-]+$#i', $domain) !== 1) {
                throw new \InvalidArgumentException('Домен должен быть без протокола, например example.com.');
            }
            $fields['SERVER_NAME'] = $domain;
        }
        if (array_key_exists('email', $args)) {
            $email = trim((string)$args['email']);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Некорректный email.');
            }
            $fields['EMAIL'] = $email;
        }
        if (array_key_exists('sort', $args)) {
            $fields['SORT'] = IblockElementFields::sort($args['sort']);
        }
        if (array_key_exists('active', $args)) {
            $fields['ACTIVE'] = ($args['active'] ?? true) !== false ? 'Y' : 'N';
        }

        if ($fields === array()) {
            throw new \InvalidArgumentException('Не передано ни одного поля для изменения.');
        }

        $before = array(
            'SITE_NAME' => (string)$site['SITE_NAME'],
            'SERVER_NAME' => (string)$site['SERVER_NAME'],
            'EMAIL' => (string)($site['EMAIL'] ?? ''),
            'SORT' => (int)$site['SORT'],
            'ACTIVE' => (string)$site['ACTIVE'],
        );

        $lines = array();
        foreach ($fields as $field => $value) {
            $lines[] = $field . ': ' . var_export($before[$field] ?? null, true) . ' → ' . var_export($value, true);
        }

        return array(
            'summary' => 'Настройки сайта ' . $lid,
            'payload' => array(
                'args' => array('lid' => $lid, 'fields' => $fields),
                'before' => $before,
            ),
            'preview' => implode("\n", $lines),
        );
    }

    public static function apply(array $payload): array
    {
        $lid = (string)($payload['args']['lid'] ?? '');

        $result = SiteTable::update($lid, (array)($payload['args']['fields'] ?? array()));
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        return array('lid' => $lid, 'updated' => array_keys((array)($payload['args']['fields'] ?? array())));
    }

    public static function rollback(array $payload): void
    {
        $lid = (string)($payload['args']['lid'] ?? '');
        $before = (array)($payload['before'] ?? array());

        if ($lid !== '' && $before !== array()) {
            SiteTable::update($lid, $before);
        }
    }
}
