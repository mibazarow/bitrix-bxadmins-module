<?php

declare(strict_types=1);

namespace Mibazarow\Bxadmins\Service;

use Bitrix\Main\Type\DateTime;

/**
 * Период отчёта: from/to в YYYY-MM-DD или последние N дней.
 * Общий для sales_report и top_products, чтобы не дублировать разбор дат.
 */
final class ReportPeriod
{
    /** @return array{from:DateTime,to:DateTime,label:string} */
    public static function fromArgs(array $args, int $defaultDays = 30): array
    {
        $from = trim((string)($args['from'] ?? ''));
        $to = trim((string)($args['to'] ?? ''));
        $days = max(1, min(3660, (int)($args['days'] ?? $defaultDays)));

        if ($from !== '' && !self::isDate($from)) {
            throw new \InvalidArgumentException('from должен быть датой в формате YYYY-MM-DD.');
        }

        if ($to !== '' && !self::isDate($to)) {
            throw new \InvalidArgumentException('to должен быть датой в формате YYYY-MM-DD.');
        }

        if ($to === '') {
            $to = date('Y-m-d');
        }

        if ($from === '') {
            $from = date('Y-m-d', (int)strtotime($to . ' -' . ($days - 1) . ' days'));
        }

        if ($from > $to) {
            throw new \InvalidArgumentException('from не может быть позже to.');
        }

        return array(
            'from' => DateTime::createFromPhp(new \DateTime($from . ' 00:00:00')),
            'to' => DateTime::createFromPhp(new \DateTime($to . ' 23:59:59')),
            'label' => $from . '..' . $to,
        );
    }

    private static function isDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }
}
