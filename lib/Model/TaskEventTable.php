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
 * Журнал событий задачи для SSE-доставки в браузер.
 * Пишется демоном через worker.php, читается admin/stream.php.
 */
class TaskEventTable extends DataManager
{
    use DeleteByFilterTrait;

    public static function getTableName()
    {
        return 'b_mibazarow_bxadmins_task_event';
    }

    public static function getMap()
    {
        return array(
            (new IntegerField('ID'))
                ->configurePrimary()
                ->configureAutocomplete(),
            (new IntegerField('USER_ID'))
                ->configureRequired(),
            (new IntegerField('TASK_ID'))
                ->configureRequired(),
            (new IntegerField('CHAT_ID'))
                ->configureRequired(),
            (new StringField('TYPE'))
                ->configureRequired(),
            (new TextField('PAYLOAD'))
                ->configureRequired(),
            (new DatetimeField('CREATED_AT'))
                ->configureRequired(),
        );
    }
}
