<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * ORM-таблица чатов (сессий) модуля.
 */
class ChatTable extends DataManager
{
    public static function getTableName()
    {
        return 'b_mibazarow_bxadmins_chat';
    }

    public static function getMap()
    {
        return array(
            (new IntegerField('ID'))
                ->configurePrimary()
                ->configureAutocomplete(),
            (new StringField('TITLE'))
                ->configureRequired()
                ->configureDefaultValue('Новый чат'),
            (new IntegerField('USER_ID'))
                ->configureRequired(),
            (new StringField('PINNED'))
                ->configureDefaultValue('N'),
            // Сводка сжатого контекста и ID последнего сообщения, вошедшего в неё.
            (new TextField('SUMMARY')),
            (new IntegerField('SUMMARY_UP_TO_ID'))
                ->configureDefaultValue(0),
            (new DatetimeField('CREATED_AT'))
                ->configureRequired(),
            (new DatetimeField('UPDATED_AT'))
                ->configureRequired(),
        );
    }
}
