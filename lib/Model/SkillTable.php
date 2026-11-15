<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/** Agent skills: named instructions applied to matching tasks. */
class SkillTable extends DataManager
{
    public static function getTableName()
    {
        return 'b_mibazarow_bxadmins_skill';
    }

    public static function getMap()
    {
        return array(
            (new IntegerField('ID'))
                ->configurePrimary()
                ->configureAutocomplete(),
            (new StringField('NAME', array('size' => 150)))
                ->configureRequired(),
            (new StringField('DESCRIPTION', array('size' => 500))),
            (new TextField('INSTRUCTIONS'))
                ->configureRequired()
                ->configureLong(),
            (new StringField('ENABLED', array('size' => 1)))
                ->configureDefaultValue('Y'),
            (new StringField('TAGS', array('size' => 500))),
            (new StringField('WHEN_TO_USE', array('size' => 500))),
            (new IntegerField('PRIORITY'))
                ->configureDefaultValue(100),
            (new IntegerField('CREATED_BY'))
                ->configureDefaultValue(0),
            (new StringField('SOURCE', array('size' => 20)))
                ->configureDefaultValue('chat'),
            (new IntegerField('CHAT_ID'))
                ->configureDefaultValue(0),
            (new IntegerField('USE_COUNT'))
                ->configureDefaultValue(0),
            (new DatetimeField('LAST_USED_AT')),
            (new DatetimeField('DELETED_AT')),
            (new DatetimeField('CREATED_AT'))
                ->configureRequired(),
            (new DatetimeField('UPDATED_AT'))
                ->configureRequired(),
        );
    }
}
