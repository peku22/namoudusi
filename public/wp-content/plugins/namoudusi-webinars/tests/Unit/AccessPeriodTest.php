<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Tests\Unit;

use Namoudusi\Webinars\Access\AccessPeriod;
use PHPUnit\Framework\TestCase;

final class AccessPeriodTest extends TestCase
{
    private static function utc(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }

    public function testNoAccessDaysMeansNoTimeLimit(): void
    {
        self::assertNull(AccessPeriod::expiresAt(null, self::utc('2026-11-01 10:00')));
    }

    public function testRecordedCountsFromPurchase(): void
    {
        self::assertEquals(
            self::utc('2026-12-01 10:00'),
            AccessPeriod::expiresAt(30, self::utc('2026-11-01 10:00'))
        );
    }

    public function testLiveCountsFromEventStartWhenBoughtInAdvance(): void
    {
        self::assertEquals(
            self::utc('2027-01-15 18:00'),
            AccessPeriod::expiresAt(30, self::utc('2026-11-01 10:00'), self::utc('2026-12-16 18:00'))
        );
    }

    public function testLiveBoughtAfterEventCountsFromPurchase(): void
    {
        self::assertEquals(
            self::utc('2027-01-10 09:00'),
            AccessPeriod::expiresAt(30, self::utc('2026-12-11 09:00'), self::utc('2026-12-01 18:00'))
        );
    }

    public function testExtendKeepsLaterExpiry(): void
    {
        $earlier = self::utc('2026-12-01');
        $later = self::utc('2027-03-01');
        self::assertEquals($later, AccessPeriod::extend($earlier, $later));
        self::assertEquals($later, AccessPeriod::extend($later, $earlier));
    }

    public function testExtendWithNoLimitOnEitherSideIsNoLimit(): void
    {
        self::assertNull(AccessPeriod::extend(null, self::utc('2027-01-01')));
        self::assertNull(AccessPeriod::extend(self::utc('2027-01-01'), null));
    }
}
