<?php

declare(strict_types=1);

namespace Namoudusi\Webinars;

/**
 * Integration settings. Secrets live only in wp-config.php constants, never in the database.
 */
final class Config
{
    public const BUNNY_CONSTANTS = ['NAMOUDUSI_BUNNY_LIBRARY_ID', 'NAMOUDUSI_BUNNY_TOKEN_KEY'];
    public const ZOOM_CONSTANTS = [
        'NAMOUDUSI_ZOOM_ACCOUNT_ID',
        'NAMOUDUSI_ZOOM_CLIENT_ID',
        'NAMOUDUSI_ZOOM_CLIENT_SECRET',
    ];

    public static function get(string $constant): string
    {
        return defined($constant) ? (string) constant($constant) : '';
    }

    public static function hasBunny(): bool
    {
        return self::allDefined(self::BUNNY_CONSTANTS);
    }

    public static function hasZoom(): bool
    {
        return self::allDefined(self::ZOOM_CONSTANTS);
    }

    /**
     * @return list<string>
     */
    public static function missingConstants(): array
    {
        return array_values(array_filter(
            [...self::BUNNY_CONSTANTS, ...self::ZOOM_CONSTANTS],
            static fn (string $constant): bool => self::get($constant) === ''
        ));
    }

    /**
     * @param list<string> $constants
     */
    private static function allDefined(array $constants): bool
    {
        foreach ($constants as $constant) {
            if (self::get($constant) === '') {
                return false;
            }
        }
        return true;
    }
}
