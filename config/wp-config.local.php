<?php
/**
 * Local (Docker) configuration – template copied to public/wp-config.php by bin/setup.sh.
 * Staging/production get their own wp-config.php on the server.
 */

// ** Environment ** //
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_HOME', 'http://namoudusi.localhost:8180' );
define( 'WP_SITEURL', WP_HOME );

// ** Database – values come from compose.yaml / .env ** //
define( 'DB_NAME', getenv( 'WORDPRESS_DB_NAME' ) ?: 'namoudusi' );
define( 'DB_USER', getenv( 'WORDPRESS_DB_USER' ) ?: 'namoudusi' );
define( 'DB_PASSWORD', getenv( 'WORDPRESS_DB_PASSWORD' ) ?: '' );
define( 'DB_HOST', getenv( 'WORDPRESS_DB_HOST' ) ?: 'db' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
$table_prefix = 'wp_';

// ** Salts – regenerate with `wp config shuffle-salts` ** //
define( 'AUTH_KEY', 'put your unique phrase here' );
define( 'SECURE_AUTH_KEY', 'put your unique phrase here' );
define( 'LOGGED_IN_KEY', 'put your unique phrase here' );
define( 'NONCE_KEY', 'put your unique phrase here' );
define( 'AUTH_SALT', 'put your unique phrase here' );
define( 'SECURE_AUTH_SALT', 'put your unique phrase here' );
define( 'LOGGED_IN_SALT', 'put your unique phrase here' );
define( 'NONCE_SALT', 'put your unique phrase here' );

// ** Debugging ** //
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );          // wp-content/debug.log
define( 'WP_DEBUG_DISPLAY', false );
define( 'SCRIPT_DEBUG', true );

// ** Behaviour ** //
define( 'DISABLE_WP_CRON', true );       // cron container runs due events every minute
define( 'WP_AUTO_UPDATE_CORE', false );  // updates are deployed, not applied in place
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'DISALLOW_FILE_EDIT', true );
define( 'FS_METHOD', 'direct' );
define( 'WP_MEMORY_LIMIT', '256M' );
define( 'WP_POST_REVISIONS', 10 );

// ** Integrations – API keys live here, never in the DB ** //
// Without Zoom keys the local site uses a fake Zoom client (dummy join URLs).
// define( 'NAMOUDUSI_BUNNY_LIBRARY_ID', '' );
// define( 'NAMOUDUSI_BUNNY_TOKEN_KEY', '' );     // Stream library → Security → token authentication key
// define( 'NAMOUDUSI_ZOOM_ACCOUNT_ID', '' );     // Zoom Server-to-Server OAuth app
// define( 'NAMOUDUSI_ZOOM_CLIENT_ID', '' );
// define( 'NAMOUDUSI_ZOOM_CLIENT_SECRET', '' );

/* That's all, stop editing! Happy publishing. */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
