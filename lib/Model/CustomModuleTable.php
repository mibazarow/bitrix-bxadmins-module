<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Реестр модулей, которые агент создал сам.
 * Только они открыты для правки: сторонние и системные модули — только чтение.
 */
class CustomModuleTable extends DataManager
{
    public static function getTableName()
    {
        return 'b_mibazarow_bxadmins_custom_module';
    }

    public static function getMap()
    {
        return array(
            (new IntegerField('ID'))
                ->configurePrimary()
                ->configureAutocomplete(),
            (new StringField('MODULE_ID', array('size' => 100)))
                ->configureRequired()
                ->configureUnique(),
            (new StringField('NAME', array('size' => 255))),
            (new IntegerField('CREATED_BY'))
                ->configureRequired(),
            (new DatetimeField('CREATED_AT'))
                ->configureRequired(),
            (new DatetimeField('UPDATED_AT'))
                ->configureRequired(),
        );
    }
}
