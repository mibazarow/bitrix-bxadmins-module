<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Type\DateTime;
use Mibazarow\Bxadmins\Model\LlmProfileTable;

/**
 * Подключения к провайдерам: несколько сохранённых, активно ровно одно.
 * При сбое активного харнес предлагает переключиться на другое сохранённое.
 */
class LlmProfileService
{
    /** Активный профиль текущего запроса. */
    private static ?array $active = null;
    private static bool $activeLoaded = false;

    /** @return array<int,array> */
    public static function list(): array
    {
        $rows = array();

        foreach (LlmProfileTable::getList(array(
            'select' => array('ID', 'NAME', 'ENDPOINT', 'MODEL', 'REASONING', 'CONTEXT_WINDOW', 'ACTIVE', 'UPDATED_AT'),
            'order' => array('ACTIVE' => 'DESC', 'ID' => 'ASC'),
        ))->fetchAll() as $row) {
            $rows[] = array(
                'id' => (int)$row['ID'],
                'name' => (string)$row['NAME'],
                'endpoint' => (string)$row['ENDPOINT'],
                'model' => (string)$row['MODEL'],
                'reasoning' => (string)$row['REASONING'],
                'context_window' => (int)$row['CONTEXT_WINDOW'],
                'active' => (string)$row['ACTIVE'] === 'Y',
            );
        }

        return $rows;
    }

    /** @return array|null активное подключение или null */
    public static function active(): ?array
    {
        if (self::$activeLoaded) {
            return self::$active;
        }

        self::$activeLoaded = true;
        self::$active = null;

        try {
            $row = LlmProfileTable::getList(array(
                'select' => array('ID', 'NAME', 'ENDPOINT', 'API_KEY', 'MODEL', 'REASONING', 'CONTEXT_WINDOW'),
                'filter' => array('=ACTIVE' => 'Y'),
                'order' => array('ID' => 'ASC'),
                'limit' => 1,
            ))->fetch();

            if ($row) {
                self::$active = array(
                    'id' => (int)$row['ID'],
                    'name' => (string)$row['NAME'],
                    'endpoint' => (string)$row['ENDPOINT'],
                    'api_key' => (string)$row['API_KEY'],
                    'model' => (string)$row['MODEL'],
                    'reasoning' => (string)$row['REASONING'],
                    'context_window' => (int)$row['CONTEXT_WINDOW'],
                );
            }
        } catch (\Throwable $e) {
            // Таблицы ещё нет (модуль обновляется) — работаем на опциях.
            self::$active = null;
        }

        return self::$active;
    }

    /**
     * Создаёт или обновляет подключение.
     *
     * @return int ID профиля
     */
    public static function save(int $id, string $name, string $endpoint, string $apiKey, string $model, string $reasoning, int $contextWindow): int
    {
        $name = trim($name);
        $endpoint = rtrim(trim($endpoint), '/');
        $model = trim($model);

        if ($name === '') {
            throw new \InvalidArgumentException('Название подключения обязательно.');
        }

        if ($endpoint === '' || $model === '') {
            throw new \InvalidArgumentException('Нужны endpoint и модель.');
        }

        if (!in_array(parse_url($endpoint, PHP_URL_SCHEME), array('http', 'https'), true)) {
            throw new \InvalidArgumentException('Endpoint должен быть URL со схемой http или https.');
        }

        if ($reasoning === 'medium') {
            $reasoning = 'low';
        }

        if (!in_array($reasoning, array('none', 'low', 'high'), true)) {
            $reasoning = 'low';
        }

        $contextWindow = max(1000, $contextWindow);
        $fields = array(
            'NAME' => mb_substr($name, 0, 100),
            'ENDPOINT' => mb_substr($endpoint, 0, 255),
            'MODEL' => mb_substr($model, 0, 150),
            'REASONING' => $reasoning,
            'CONTEXT_WINDOW' => $contextWindow,
            'UPDATED_AT' => new DateTime(),
        );

        if ($apiKey !== '') {
            $fields['API_KEY'] = mb_substr($apiKey, 0, 255);
        }

        if ($id > 0) {
            if (!LlmProfileTable::getById($id)->fetch()) {
                throw new \InvalidArgumentException('Подключение #' . $id . ' не найдено.');
            }

            $result = LlmProfileTable::update($id, $fields);
            if (!$result->isSuccess()) {
                throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
            }

            self::resetCache();

            return $id;
        }

        $fields['ACTIVE'] = self::hasAny() ? 'N' : 'Y';
        $fields['CREATED_AT'] = new DateTime();

        $result = LlmProfileTable::add($fields);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        self::resetCache();

        return (int)$result->getId();
    }

    /** Делает подключение активным, снимая флаг с остальных. */
    public static function activate(int $id): void
    {
        if (!LlmProfileTable::getById($id)->fetch()) {
            throw new \InvalidArgumentException('Подключение #' . $id . ' не найдено.');
        }

        // Снимаем флаг со всех остальных: активным может быть ровно одно подключение.
        foreach (LlmProfileTable::getList(array(
            'select' => array('ID'),
            'filter' => array('=ACTIVE' => 'Y', '!=ID' => $id),
        ))->fetchAll() as $row) {
            LlmProfileTable::update((int)$row['ID'], array('ACTIVE' => 'N'));
        }

        LlmProfileTable::update($id, array('ACTIVE' => 'Y', 'UPDATED_AT' => new DateTime()));

        self::resetCache();
    }

    /** Удаляет подключение; если оно было активным — активирует следующее. */
    public static function remove(int $id): void
    {
        $profile = LlmProfileTable::getById($id)->fetch();
        if (!$profile) {
            return;
        }

        $wasActive = (string)$profile['ACTIVE'] === 'Y';
        LlmProfileTable::delete($id);

        if ($wasActive) {
            $next = LlmProfileTable::getList(array(
                'select' => array('ID'),
                'order' => array('ID' => 'ASC'),
                'limit' => 1,
            ))->fetch();

            if ($next) {
                LlmProfileTable::update((int)$next['ID'], array('ACTIVE' => 'Y'));
            }
        }

        self::resetCache();
    }

    /**
     * Другие сохранённые подключения — для предложения переключиться.
     *
     * @return array<int,array{id:int,name:string,model:string}>
     */
    public static function alternatives(int $exceptId): array
    {
        $result = array();

        foreach (LlmProfileTable::getList(array(
            'select' => array('ID', 'NAME', 'MODEL', 'ENDPOINT'),
            'filter' => array('!=ID' => $exceptId),
            'order' => array('ID' => 'ASC'),
        ))->fetchAll() as $row) {
            if (trim((string)$row['ENDPOINT']) === '' || trim((string)$row['MODEL']) === '') {
                continue;
            }

            $result[] = array(
                'id' => (int)$row['ID'],
                'name' => (string)$row['NAME'],
                'model' => (string)$row['MODEL'],
            );
        }

        return $result;
    }

    /**
     * Переносит старое единственное подключение из опций в таблицу профилей.
     * Вызывается при установке/обновлении; повторный вызов безопасен.
     */
    public static function ensureFromOptions(): void
    {
        if (self::hasAny()) {
            return;
        }

        $endpoint = trim((string)\Bitrix\Main\Config\Option::get('mibazarow.bxadmins', 'llm_endpoint', ''));
        $model = trim((string)\Bitrix\Main\Config\Option::get('mibazarow.bxadmins', 'llm_model', ''));

        if ($endpoint === '' || $model === '') {
            return;
        }

        try {
            LlmProfileTable::add(array(
                'NAME' => 'Основное подключение',
                'ENDPOINT' => $endpoint,
                'API_KEY' => (string)\Bitrix\Main\Config\Option::get('mibazarow.bxadmins', 'llm_api_key', ''),
                'MODEL' => $model,
                'REASONING' => (string)\Bitrix\Main\Config\Option::get('mibazarow.bxadmins', 'llm_reasoning', 'low'),
                'CONTEXT_WINDOW' => (int)\Bitrix\Main\Config\Option::get('mibazarow.bxadmins', 'context_window', '240000'),
                'ACTIVE' => 'Y',
                'CREATED_AT' => new DateTime(),
                'UPDATED_AT' => new DateTime(),
            ));
        } catch (\Throwable $e) {
            Logger::error('LlmProfile: перенос подключения из опций: ' . $e->getMessage());
        }

        self::resetCache();
    }

    /** Сбрасывает кэш активного профиля (после сохранения настроек). */
    private static function resetCache(): void
    {
        self::$active = null;
        self::$activeLoaded = false;
    }

    private static function hasAny(): bool
    {
        try {
            return LlmProfileTable::getCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
