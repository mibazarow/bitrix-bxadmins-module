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
 * ORM-таблица сообщений чата, включая вызовы инструментов и их результаты.
 */
class MessageTable extends DataManager
{
    use DeleteByFilterTrait;

    public static function getTableName()
    {
        return 'b_mibazarow_bxadmins_message';
    }

    public static function getMap()
    {
        return array(
            (new IntegerField('ID'))
                ->configurePrimary()
                ->configureAutocomplete(),
            (new IntegerField('CHAT_ID'))
                ->configureRequired(),
            (new StringField('ROLE'))
                ->configureRequired(),
            (new TextField('CONTENT')),
            (new StringField('TOOL_NAME')),
            (new StringField('TOOL_CALL_ID')),
            (new TextField('TOOL_ARGS')),
            (new StringField('IS_ERROR', array('default' => 'N'))),
            (new TextField('ATTACHMENTS')),
            (new DatetimeField('CREATED_AT'))
                ->configureRequired(),
        );
    }
}
