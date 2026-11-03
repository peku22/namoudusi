<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Access;

/**
 * Human-readable access dates and statuses in the site timezone.
 */
final class AccessFormatter
{
    public static function date(?\DateTimeImmutable $date): string
    {
        if ($date === null) {
            return '–';
        }
        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $date->getTimestamp()) ?: '';
    }

    public static function expiry(?\DateTimeImmutable $expiresAt): string
    {
        if ($expiresAt === null) {
            return __('no time limit', 'namoudusi-webinars');
        }
        /* translators: %s: date and time */
        return sprintf(__('until %s', 'namoudusi-webinars'), self::date($expiresAt));
    }

    public static function status(Entitlement $entitlement): string
    {
        return match ($entitlement->status(Clock::now())) {
            Entitlement::STATUS_ACTIVE => __('Active', 'namoudusi-webinars'),
            Entitlement::STATUS_EXPIRED => __('Expired', 'namoudusi-webinars'),
            default => __('Revoked', 'namoudusi-webinars'),
        };
    }

    public static function source(Entitlement $entitlement): string
    {
        return $entitlement->source === Entitlement::SOURCE_MANUAL
            ? __('Manual', 'namoudusi-webinars')
            : __('Order', 'namoudusi-webinars');
    }
}
