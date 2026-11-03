<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Tests\Unit;

use Namoudusi\Webinars\Video\BunnyStream;
use PHPUnit\Framework\TestCase;

final class BunnyStreamTest extends TestCase
{
    private const VIDEO = '0b4e6a2c-1d3f-4a5b-9c8d-7e6f5a4b3c2d';

    public function testTokenMatchesBunnyFormula(): void
    {
        // sha256('test-token-key' . VIDEO . '1767229200'), computed independently.
        self::assertSame(
            '70a0a7ce6062a02ba8c66e39bde9502a7506eaf0c4d400194210f174b15ba073',
            (new BunnyStream('123', 'test-token-key'))->token(self::VIDEO, 1767229200)
        );
    }

    public function testEmbedUrlIsSignedAndExpires(): void
    {
        $stream = new BunnyStream('98765', 'test-token-key', 3600);
        $url = $stream->embedUrl(self::VIDEO, 1767225600);

        self::assertStringStartsWith('https://iframe.mediadelivery.net/embed/98765/' . self::VIDEO . '?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('1767229200', $query['expires']);
        self::assertSame($stream->token(self::VIDEO, 1767229200), $query['token']);
    }

    public function testDifferentVideoOrExpiryGivesDifferentToken(): void
    {
        $stream = new BunnyStream('1', 'key');
        $token = $stream->token(self::VIDEO, 100);
        self::assertNotSame($token, $stream->token(self::VIDEO, 101));
        self::assertNotSame($token, $stream->token('1b4e6a2c-1d3f-4a5b-9c8d-7e6f5a4b3c2d', 100));
    }

    public function testVideoIdValidation(): void
    {
        self::assertTrue(BunnyStream::isValidVideoId(self::VIDEO));
        self::assertTrue(BunnyStream::isValidVideoId(strtoupper(self::VIDEO)));
        self::assertFalse(BunnyStream::isValidVideoId('abc-123'));
        self::assertFalse(BunnyStream::isValidVideoId(self::VIDEO . '/../x'));
    }
}
