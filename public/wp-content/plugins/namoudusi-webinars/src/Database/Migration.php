<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Database;

interface Migration
{
    /** Sequential schema version this migration brings the database to (1, 2, 3, …). */
    public function version(): int;

    public function up(\wpdb $wpdb): void;
}
