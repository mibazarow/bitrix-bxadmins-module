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
 * Задачи по расписанию: что агент делает сам и как часто.
 *
 * Задача — это обычный запрос к агенту, который ставится в очередь по времени.
 * Выполняется тем же путём, что и сообщение в чате: модель, инструменты,
 * точки отката, история.
 */
class ScheduleTable extends DataManager
{
    use DeleteByFilterTrait;

    public static function getTableName()
    {
        return 'b_mibazarow_bxadmins_schedule';
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
            (new StringField('TITLE', array('size' => 255)))
                ->configureRequired(),
            (new TextField('PROMPT'))
                ->configureRequired(),
            (new IntegerField('INTERVAL'))
                ->configureRequired(),
            (new StringField('ACTIVE', array('size' => 1)))
                ->configureDefaultValue('Y'),
            (new DatetimeField('NEXT_RUN')),
            (new DatetimeField('LAST_RUN')),
            (new StringField('LAST_STATUS', array('size' => 20))),
            (new TextField('LAST_RESULT')),
            (new IntegerField('RUNS'))
                ->configureDefaultValue(0),
            (new DatetimeField('CREATED_AT'))
                ->configureRequired(),
            (new DatetimeField('UPDATED_AT'))
                ->configureRequired(),
        );
    }
}
