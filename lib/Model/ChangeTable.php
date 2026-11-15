<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * ORM-таблица изменений: предложенные, подтверждённые, выполненные и откаченные правки.
 */
class ChangeTable extends DataManager
{
    use DeleteByFilterTrait;

    public static function getTableName()
    {
        return 'b_mibazarow_bxadmins_change';
    }

    public static function getMap()
    {
        return array(
            (new IntegerField('ID'))
                ->configurePrimary()
                ->configureAutocomplete(),
            (new IntegerField('CHAT_ID')),
            (new IntegerField('USER_ID'))
                ->configureRequired(),
            (new StringField('DOMAIN', array('size' => 50)))
                ->configureRequired(),
            (new StringField('TOOL', array('size' => 100)))
                ->configureRequired(),
            (new StringField('SUMMARY', array('size' => 500)))
                ->configureRequired(),
            (new TextField('PREVIEW')),
            (new TextField('PAYLOAD'))
                ->configureRequired()
                ->configureLong(),
            (new StringField('STATUS', array('size' => 20)))
                ->configureRequired(),
            (new TextField('RESULT'))
                ->configureLong(),
            (new StringField('BATCH_ID', array('size' => 64))),
            (new StringField('BACKUP_FILE', array('size' => 255))),
            (new DatetimeField('CREATED_AT'))
                ->configureRequired(),
            (new DatetimeField('UPDATED_AT'))
                ->configureRequired(),
        );
    }
}
