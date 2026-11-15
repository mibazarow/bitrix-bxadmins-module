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
 * ORM-таблица очереди задач агента. Задачу выполняет Node-демон,
 * PHP хранит состояние и исполняет инструменты.
 */
class TaskTable extends DataManager
{
    use DeleteByFilterTrait;

    public static function getTableName()
    {
        return 'b_mibazarow_bxadmins_task';
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
                ->configureRequired(),
            (new StringField('STATUS'))
                ->configureRequired(),
            (new TextField('PROMPT'))
                ->configureRequired(),
            (new StringField('BYPASS'))
                ->configureDefaultValue('N'),
            (new StringField('CANCEL'))
                ->configureDefaultValue('N'),
            (new TextField('ATTACHMENTS')),
            (new TextField('RESULT')),
            (new TextField('ERROR')),
            (new DatetimeField('CREATED_AT'))
                ->configureRequired(),
            (new DatetimeField('STARTED_AT')),
            (new DatetimeField('FINISHED_AT')),
        );
    }
}
