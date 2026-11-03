<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Database;

use Namoudusi\Webinars\Database\Migrations\AddZoomMeetingId;
use Namoudusi\Webinars\Database\Migrations\CreateEntitlementsTable;

/**
 * Applies versioned schema migrations. The current version is stored in the `nmd_db_version` option.
 */
final class Migrator
{
    public const OPTION = 'nmd_db_version';

    /**
     * Append new migrations at the end; never reorder or remove applied ones.
     *
     * @var list<class-string<Migration>>
     */
    public const MIGRATIONS = [
        CreateEntitlementsTable::class,
        AddZoomMeetingId::class,
    ];

    public static function targetVersion(): int
    {
        return count(self::MIGRATIONS);
    }

    public static function currentVersion(): int
    {
        return (int) get_option(self::OPTION, 0);
    }

    public static function migrateIfNeeded(): void
    {
        if (self::currentVersion() < self::targetVersion()) {
            self::migrate();
        }
    }

    public static function migrate(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $current = self::currentVersion();
        foreach (self::MIGRATIONS as $class) {
            $migration = new $class();
            if ($migration->version() <= $current) {
                continue;
            }
            $migration->up($wpdb);
            update_option(self::OPTION, $migration->version(), true);
            $current = $migration->version();
        }
    }
}
