<?php

declare(strict_types=1);

namespace JmaXml\Feed\Internal;

use DateTimeImmutable;

/**
 * Parses W3C/RFC 3339 date-times as used in the Atom feeds, keeping their timezone.
 *
 * @internal
 *
 * @author shimomo
 */
final class W3cDateTime
{
    private const string PATTERN = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d+))?(Z|[+-]\d{2}:\d{2})$/';

    public static function parse(string $value): ?DateTimeImmutable
    {
        if (preg_match(self::PATTERN, trim($value), $matches) !== 1) {
            return null;
        }

        [, $dateTime, $fraction, $timezone] = $matches;
        $format = 'Y-m-d\TH:i:s';

        if ($fraction !== '') {
            $format .= '.u';
            $dateTime .= '.' . substr($fraction . '000000', 0, 6);
        }

        $parsed = DateTimeImmutable::createFromFormat($format . 'T', $dateTime . $timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $parsed;
    }
}
