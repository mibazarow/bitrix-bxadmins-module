<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/** Agent task checklist for a chat. */
class TodoTable extends DataManager
{
    use DeleteByFilterTrait;

    public static function getTableName()
    {
        return 'b_mibazarow_bxadmins_todo';
    }

    public static function getMap()
    {
        return array(
            (new IntegerField('ID'))
                ->configurePrimary()
                ->configureAutocomplete(),
            (new IntegerField('CHAT_ID'))
                ->configureRequired(),
            (new IntegerField('USER_ID'))
                ->configureDefaultValue(0),
            (new StringField('TEXT', array('size' => 500)))
                ->configureRequired(),
            (new StringField('STATUS', array('size' => 20)))
                ->configureDefaultValue('pending'),
            (new IntegerField('SORT'))
                ->configureDefaultValue(100),
            (new DatetimeField('CREATED_AT'))
                ->configureRequired(),
            (new DatetimeField('UPDATED_AT'))
                ->configureRequired(),
        );
    }
}
