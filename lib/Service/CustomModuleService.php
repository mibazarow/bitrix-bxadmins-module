<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Type\DateTime;
use Mibazarow\Bxadmins\Model\CustomModuleTable;

/**
 * Владение модулями: агент правит и удаляет только те модули, которые создал сам.
 *
 * Модули ядра и сторонние модули (в /bitrix/modules и /local/modules) остаются
 * доступны для чтения, но не для записи. Признак владения хранится в таблице,
 * а не в файлах: маркер в каталоге модуля можно подделать или потерять при копировании.
 */
final class CustomModuleService
{
    /** ID модуля: vendor.name, оба сегмента начинаются с буквы. */
    public const ID_PATTERN = '/^[a-z][a-z0-9_]{0,49}\.[a-z][a-z0-9_]{0,49}$/';

    /** Модули, которые агент не создаёт и не удаляет никогда. */
    public const PROTECTED = array('main', 'mibazarow.bxadmins');

    /** Вендоры, чьи модули принадлежат ядру и вендору, а не агенту. */
    public const RESERVED_VENDORS = array('bitrix', 'main');

    /** @var array<string,bool>|null */
    private static ?array $owned = null;

    /**
     * Проверяет и нормализует ID нового модуля.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertId(string $moduleId): string
    {
        $moduleId = strtolower(trim($moduleId));

        if (preg_match(self::ID_PATTERN, $moduleId) !== 1) {
            throw new \InvalidArgumentException(
                'ID модуля должен быть в формате vendor.name: строчные латинские буквы, цифры и «_», '
                . 'оба сегмента начинаются с буквы. Например: acme.forms.'
            );
        }

        if (in_array($moduleId, self::PROTECTED, true)) {
            throw new \InvalidArgumentException('Модуль "' . $moduleId . '" защищён и не создаётся агентом.');
        }

        $vendor = explode('.', $moduleId)[0];
        if (in_array($vendor, self::RESERVED_VENDORS, true)) {
            throw new \InvalidArgumentException(
                'Вендор "' . $vendor . '" зарезервирован: модули ядра и вендора агент не создаёт и не меняет.'
            );
        }

        return $moduleId;
    }

    /** Модуль создан агентом и открыт ему для правки. */
    public static function isOwned(string $moduleId): bool
    {
        $moduleId = strtolower(trim($moduleId));

        if ($moduleId === '') {
            return false;
        }

        $owned = self::ownedMap();

        return isset($owned[$moduleId]);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function assertOwned(string $moduleId): string
    {
        $moduleId = strtolower(trim($moduleId));

        if (!self::isOwned($moduleId)) {
            throw new \InvalidArgumentException(
                'Модуль "' . $moduleId . '" не создан агентом: менять и удалять можно только свои модули. '
                . 'Сторонние и системные модули доступны лишь для чтения.'
            );
        }

        return $moduleId;
    }

    /**
     * Все созданные агентом модули.
     *
     * @return array<int,array{module_id:string,name:string,created_by:int,created_at:string}>
     */
    public static function all(): array
    {
        try {
            $rows = CustomModuleTable::getList(array(
                'select' => array('MODULE_ID', 'NAME', 'CREATED_BY', 'CREATED_AT'),
                'order' => array('NAME' => 'ASC', 'MODULE_ID' => 'ASC'),
            ))->fetchAll();
        } catch (\Throwable $e) {
            // Таблицы может не быть до переустановки модуля — читаем как «своих нет».
            return array();
        }

        $result = array();
        foreach ($rows as $row) {
            $result[] = array(
                'module_id' => (string)$row['MODULE_ID'],
                'name' => (string)$row['NAME'],
                'created_by' => (int)$row['CREATED_BY'],
                'created_at' => $row['CREATED_AT'] instanceof \Bitrix\Main\Type\DateTime
                    ? $row['CREATED_AT']->toString()
                    : (string)$row['CREATED_AT'],
            );
        }

        return $result;
    }

    /**
     * Запоминает модуль как созданный агентом.
     *
     * @throws \RuntimeException
     */
    public static function register(string $moduleId, string $name, int $userId): void
    {
        $moduleId = self::assertId($moduleId);

        if (self::isOwned($moduleId)) {
            return;
        }

        $now = new DateTime();

        try {
            $result = CustomModuleTable::add(array(
                'MODULE_ID' => $moduleId,
                'NAME' => mb_substr(trim($name), 0, 255),
                'CREATED_BY' => $userId,
                'CREATED_AT' => $now,
                'UPDATED_AT' => $now,
            ));
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Не удалось записать модуль в реестр владения. Переустановите модуль BXADMIN-S, '
                . 'чтобы создать служебную таблицу.',
                0,
                $e
            );
        }

        if (!$result->isSuccess()) {
            throw new \RuntimeException('Не удалось записать модуль в реестр владения: '
                . implode('; ', $result->getErrorMessages()));
        }

        self::$owned[$moduleId] = true;
    }

    /** Убирает модуль из реестра (после удаления каталога). */
    public static function forget(string $moduleId): void
    {
        $moduleId = strtolower(trim($moduleId));

        try {
            $row = CustomModuleTable::getList(array(
                'select' => array('ID'),
                'filter' => array('=MODULE_ID' => $moduleId),
                'limit' => 1,
            ))->fetch();

            if ($row) {
                CustomModuleTable::delete((int)$row['ID']);
            }
        } catch (\Throwable $e) {
            // Реестр мог не существовать — удаление файлов от этого не зависит.
        }

        unset(self::$owned[$moduleId]);
    }

    /** ID модуля из пути local/modules/<id>/…, иначе null. */
    public static function moduleFromPath(string $relativePath): ?string
    {
        if (preg_match('#^local/modules/([A-Za-z0-9_.-]+)/#', $relativePath, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }

    /** Относительный путь каталога модуля. */
    public static function relativePath(string $moduleId): string
    {
        return 'local/modules/' . $moduleId;
    }

    /** @return array<string,bool> */
    private static function ownedMap(): array
    {
        if (self::$owned !== null) {
            return self::$owned;
        }

        self::$owned = array();

        try {
            foreach (CustomModuleTable::getList(array(
                'select' => array('MODULE_ID'),
            ))->fetchAll() as $row) {
                self::$owned[(string)$row['MODULE_ID']] = true;
            }
        } catch (\Throwable $e) {
            // До переустановки таблицы нет: считаем, что своих модулей нет.
        }

        return self::$owned;
    }
}
