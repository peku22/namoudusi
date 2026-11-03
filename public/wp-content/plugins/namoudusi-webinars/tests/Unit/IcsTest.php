<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Tests\Unit;

use Namoudusi\Webinars\Calendar\Ics;
use PHPUnit\Framework\TestCase;

final class IcsTest extends TestCase
{
    private function sample(string $description = 'Popis'): string
    {
        $utc = new \DateTimeZone('UTC');
        return Ics::event(
            'webinar-12-34@example.test',
            new \DateTimeImmutable('2026-12-01 17:00', $utc),
            90,
            'Úzkost a jak s ní pracovat',
            $description,
            'Zoom',
            'https://example.test/muj-ucet/webinare/12/',
            5,
            new \DateTimeImmutable('2026-11-01 10:00', $utc),
        );
    }

    public function testEventTimesAreUtcAndEndAddsDuration(): void
    {
        $ics = $this->sample();
        self::assertStringContainsString("DTSTART:20261201T170000Z\r\n", $ics);
        self::assertStringContainsString("DTEND:20261201T183000Z\r\n", $ics);
        self::assertStringContainsString("UID:webinar-12-34@example.test\r\n", $ics);
        self::assertStringContainsString("SEQUENCE:5\r\n", $ics);
        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
    }

    public function testTextIsEscaped(): void
    {
        self::assertSame('a\\, b\; c\\\\ d\\ne', Ics::escape("a, b; c\\ d\ne"));
    }

    public function testLongLinesAreFoldedWithoutBreakingUtf8(): void
    {
        $ics = $this->sample(str_repeat('Příliš žluťoučký kůň ', 10));
        foreach (explode("\r\n", $ics) as $line) {
            self::assertLessThanOrEqual(75, strlen($line), $line);
            self::assertTrue(mb_check_encoding($line, 'UTF-8'), 'Line split inside a UTF-8 character.');
        }
        // Unfolding restores the original text.
        $unfolded = str_replace("\r\n ", '', $ics);
        self::assertStringContainsString('DESCRIPTION:' . str_repeat('Příliš žluťoučký kůň ', 10), $unfolded);
    }
}
