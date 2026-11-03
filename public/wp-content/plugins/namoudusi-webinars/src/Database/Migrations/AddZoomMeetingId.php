<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Database\Migrations;

use Namoudusi\Webinars\Database\Migration;
use Namoudusi\Webinars\Database\Tables;

/**
 * Remembers which Zoom meeting a registrant belongs to, so a changed Zoom ID triggers re-registration.
 */
final class AddZoomMeetingId implements Migration
{
    public function version(): int
    {
        return 2;
    }

    public function up(\wpdb $wpdb): void
    {
        $table = Tables::entitlements();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'zoom_meeting_id'));
        if ($exists === null) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN zoom_meeting_id varchar(32) DEFAULT NULL AFTER zoom_join_url");
        }
        // phpcs:enable
    }
}
