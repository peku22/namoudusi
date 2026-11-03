<?php

/**
 * Data (access records) is kept on uninstall unless explicitly requested in wp-config.php:
 * define( 'NAMOUDUSI_WEBINARS_DELETE_DATA_ON_UNINSTALL', true );
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

$deleteData = defined('NAMOUDUSI_WEBINARS_DELETE_DATA_ON_UNINSTALL')
    && constant('NAMOUDUSI_WEBINARS_DELETE_DATA_ON_UNINSTALL') === true;
if (!$deleteData) {
    return;
}

global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}nmd_entitlements"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_option('nmd_db_version');
