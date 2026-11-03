<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Calendar;

/**
 * Minimal RFC 5545 calendar with one event.
 * Keep `uid` stable per person + webinar and raise `sequence`, so calendar apps update the entry.
 */
final class Ics
{
    public static function event(
        string $uid,
        \DateTimeImmutable $start,
        int $durationMinutes,
        string $summary,
        string $description,
        string $location,
        string $url,
        int $sequence,
        \DateTimeImmutable $stamp,
    ): string {
        $utc = new \DateTimeZone('UTC');
        $format = static fn (\DateTimeImmutable $d): string => $d->setTimezone($utc)->format('Ymd\THis\Z');
        $end = $start->modify(sprintf('+%d minutes', $durationMinutes > 0 ? $durationMinutes : 60));

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Na mou dusi//Webinare//CS',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . self::escape($uid),
            'DTSTAMP:' . $format($stamp),
            'DTSTART:' . $format($start),
            'DTEND:' . $format($end),
            'SEQUENCE:' . $sequence,
            'SUMMARY:' . self::escape($summary),
            'DESCRIPTION:' . self::escape($description),
            'LOCATION:' . self::escape($location),
            'URL:' . $url,
            'BEGIN:VALARM',
            'ACTION:DISPLAY',
            'DESCRIPTION:' . self::escape($summary),
            'TRIGGER:-PT15M',
            'END:VALARM',
            'END:VEVENT',
            'END:VCALENDAR',
        ];
        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    public static function escape(string $text): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n"],
            ['\\\\', '\;', '\\,', '\\n', '\\n'],
            $text
        );
    }

    /**
     * Folds lines longer than 75 octets without splitting UTF-8 characters.
     */
    public static function fold(string $line): string
    {
        $out = '';
        $current = '';
        foreach (mb_str_split($line) as $char) {
            $limit = $out === '' ? 75 : 74; // continuation lines start with a space
            if (strlen($current . $char) > $limit) {
                $out .= ($out === '' ? '' : "\r\n ") . $current;
                $current = '';
            }
            $current .= $char;
        }
        return $out === '' ? $current : $out . "\r\n " . $current;
    }
}
