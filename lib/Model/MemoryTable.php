<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Долговременная память агента: только то, что пользователь явно просил запомнить.
 */
class MemoryTable extends DataManager
{
    public static function getTableName()
    {
        return 'b_mibazarow_bxadmins_memory';
    }

    public static function getMap()
    {
        return array(
            (new IntegerField('ID'))
                ->configurePrimary()
                ->configureAutocomplete(),
            (new IntegerField('USER_ID'))
                ->configureDefaultValue(0),
            (new StringField('TITLE', array('size' => 150)))
                ->configureRequired(),
            (new TextField('CONTENT'))
                ->configureRequired()
                ->configureLong(),
            (new DatetimeField('CREATED_AT'))
                ->configureRequired(),
            (new DatetimeField('UPDATED_AT'))
                ->configureRequired(),
        );
    }
}
