<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

/**
 * Загрузка модулей Битрикса, с которыми работают инструменты.
 *
 * Классы модулей (`Bitrix\Catalog\PriceTable`, `Bitrix\Sale\Order` и т. д.)
 * появляются в автозагрузке только после `Loader::includeModule`. Инструменты
 * выполняются и в админке, и в демоне, где заранее загружен лишь сам модуль,
 * поэтому нужные модули подключаем перед вызовом инструмента. Повторный
 * include дёшев: ядро запоминает уже загруженные модули.
 */
final class Modules
{
    /** Модули, доступные инструментам харнеса. */
    private const USED = array(
        'iblock',
        'catalog',
        'sale',
        'currency',
        'forum',
        'blog',
        'form',
        'calendar',
        'highloadblock',
        'landing',
        'subscribe',
        'learning',
        'vote',
        'rest',
        'bizproc',
    );

    /** Подключает все модули, которые установлены на сайте. */
    public static function ensure(): void
    {
        foreach (self::USED as $moduleId) {
            if (ModuleManager::isModuleInstalled($moduleId)) {
                Loader::includeModule($moduleId);
            }
        }
    }
}
