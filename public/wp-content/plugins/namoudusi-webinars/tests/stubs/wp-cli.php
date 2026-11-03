<?php

// Minimal WP-CLI stubs for PHPStan (php-stubs/wp-cli-stubs doesn't support WordPress 7 stubs yet).
// phpcs:ignoreFile

namespace {
    class WP_CLI
    {
        public static function add_command(string $name, callable|object|string $callable, array $args = []): bool
        {
            return true;
        }

        /** @return never */
        public static function error(string|\WP_Error|\Exception $message, bool|int $exit = true): void
        {
            exit(1);
        }

        public static function success(string $message): void
        {
        }
    }
}

namespace WP_CLI\Utils {
    /**
     * @param array<mixed> $items
     * @param array<string>|string $fields
     */
    function format_items(string $format, array $items, array|string $fields): void
    {
    }
}
