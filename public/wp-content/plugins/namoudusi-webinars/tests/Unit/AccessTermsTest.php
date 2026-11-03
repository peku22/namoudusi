<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Tests\Unit;

use Namoudusi\Webinars\Access\AccessTerms;
use PHPUnit\Framework\TestCase;

final class AccessTermsTest extends TestCase
{
    public function testRoundTrip(): void
    {
        self::assertSame(90, AccessTerms::decode(AccessTerms::encode(90)));
        self::assertNull(AccessTerms::decode(AccessTerms::encode(null)));
    }

    public function testInvalidValuesMeanNoTimeLimit(): void
    {
        self::assertNull(AccessTerms::decode(''));
        self::assertNull(AccessTerms::decode('0'));
        self::assertNull(AccessTerms::decode('-5'));
    }
}
