<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

/**
 * Читаемое сообщение об ошибке из легаси-объектов ядра (CIBlockElement, CUser,
 * CForumTopic и подобных): у них свойство LAST_ERROR с HTML-разметкой.
 */
final class LegacyError
{
    public static function message(object $object, string $fallback): string
    {
        $raw = property_exists($object, 'LAST_ERROR') ? (string)$object->LAST_ERROR : '';
        $message = trim(strip_tags(str_replace(array('<br>', '<br/>', '<br />'), ' ', $raw)));
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? '');

        return $message !== '' ? mb_substr($message, 0, 500) : $fallback;
    }
}
