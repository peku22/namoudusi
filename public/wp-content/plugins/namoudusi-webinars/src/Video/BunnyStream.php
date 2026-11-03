<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Video;

use Namoudusi\Webinars\Config;

/**
 * Signed Bunny Stream embed URLs (library → Security → "Embed view token authentication").
 *
 * token = SHA256_HEX(token_key + video_id + expires)
 * The URL stops working after `expires`; together with the library's allowed-referrer list
 * a copied link is useless outside our site.
 */
final class BunnyStream
{
    public const EMBED_BASE = 'https://iframe.mediadelivery.net/embed';
    public const DEFAULT_TTL = 3600; // seconds
    public const VIDEO_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly string $libraryId,
        private readonly string $tokenKey,
        private readonly int $ttl = self::DEFAULT_TTL,
    ) {
    }

    public static function fromConfig(): ?self
    {
        if (!Config::hasBunny()) {
            return null;
        }
        return new self(
            Config::get('NAMOUDUSI_BUNNY_LIBRARY_ID'),
            Config::get('NAMOUDUSI_BUNNY_TOKEN_KEY'),
            (int) apply_filters('nmd_bunny_token_ttl', self::DEFAULT_TTL)
        );
    }

    public static function isValidVideoId(string $videoId): bool
    {
        return preg_match(self::VIDEO_ID_PATTERN, $videoId) === 1;
    }

    public function token(string $videoId, int $expires): string
    {
        return hash('sha256', $this->tokenKey . $videoId . $expires);
    }

    public function embedUrl(string $videoId, int $now): string
    {
        $expires = $now + $this->ttl;
        return sprintf('%s/%s/%s', self::EMBED_BASE, rawurlencode($this->libraryId), rawurlencode($videoId))
            . '?' . http_build_query([
                'token' => $this->token($videoId, $expires),
                'expires' => $expires,
                'autoplay' => 'false',
                'preload' => 'true',
                'responsive' => 'true',
            ]);
    }
}
