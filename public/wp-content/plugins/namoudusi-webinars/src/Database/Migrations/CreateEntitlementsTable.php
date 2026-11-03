<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Database\Migrations;

use Namoudusi\Webinars\Database\Migration;
use Namoudusi\Webinars\Database\Tables;

final class CreateEntitlementsTable implements Migration
{
    public function version(): int
    {
        return 1;
    }

    public function up(\wpdb $wpdb): void
    {
        $table = Tables::entitlements();
        $charsetCollate = $wpdb->get_charset_collate();

        // dbDelta format: one column per line, two spaces after PRIMARY KEY, KEY instead of INDEX.
        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  product_id bigint(20) unsigned NOT NULL,
  order_id bigint(20) unsigned DEFAULT NULL,
  order_item_id bigint(20) unsigned DEFAULT NULL,
  source varchar(20) NOT NULL DEFAULT 'order',
  granted_at datetime NOT NULL,
  expires_at datetime DEFAULT NULL,
  revoked_at datetime DEFAULT NULL,
  zoom_registrant_id varchar(64) DEFAULT NULL,
  zoom_join_url text DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_product (user_id,product_id),
  KEY product_id (product_id),
  KEY order_id (order_id),
  KEY expires_at (expires_at)
) {$charsetCollate};");
    }
}
