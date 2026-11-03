<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Tests\Unit;

use Namoudusi\Webinars\Access\Entitlement;
use PHPUnit\Framework\TestCase;

final class EntitlementTest extends TestCase
{
    private static function entitlement(?string $expiresAt, ?string $revokedAt = null): Entitlement
    {
        $utc = new \DateTimeZone('UTC');
        return new Entitlement(
            1,
            2,
            3,
            null,
            null,
            Entitlement::SOURCE_MANUAL,
            new \DateTimeImmutable('2026-01-01', $utc),
            $expiresAt !== null ? new \DateTimeImmutable($expiresAt, $utc) : null,
            $revokedAt !== null ? new \DateTimeImmutable($revokedAt, $utc) : null,
            null,
            null,
        );
    }

    public function testStatus(): void
    {
        $now = new \DateTimeImmutable('2026-06-01', new \DateTimeZone('UTC'));

        self::assertSame(Entitlement::STATUS_ACTIVE, self::entitlement(null)->status($now));
        self::assertSame(Entitlement::STATUS_ACTIVE, self::entitlement('2026-07-01')->status($now));
        self::assertSame(Entitlement::STATUS_EXPIRED, self::entitlement('2026-06-01')->status($now));
        self::assertSame(Entitlement::STATUS_REVOKED, self::entitlement(null, '2026-05-01')->status($now));
    }
}
