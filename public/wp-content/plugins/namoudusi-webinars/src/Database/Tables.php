<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Database;

final class Tables
{
    public static function entitlements(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'nmd_entitlements';
    }
}
