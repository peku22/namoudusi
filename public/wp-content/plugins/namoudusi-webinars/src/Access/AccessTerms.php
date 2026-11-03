<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Access;

/**
 * Access terms snapshotted on the order line at purchase, so later product edits never change what was sold.
 */
final class AccessTerms
{
    public const ITEM_META_ACCESS_DAYS = '_nmd_access_days';
    private const UNLIMITED = 'unlimited';

    public static function encode(?int $accessDays): string
    {
        return $accessDays === null ? self::UNLIMITED : (string) $accessDays;
    }

    public static function decode(string $value): ?int
    {
        return $value === self::UNLIMITED || !ctype_digit($value) || (int) $value === 0 ? null : (int) $value;
    }
}
