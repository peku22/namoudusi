<?php

// Action Scheduler functions bundled with WooCommerce (not part of php-stubs/woocommerce-stubs).
// phpcs:ignoreFile

/** @param array<mixed> $args */
function as_enqueue_async_action(string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10): int
{
    return 0;
}

/** @param array<mixed> $args */
function as_schedule_single_action(int $timestamp, string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10): int
{
    return 0;
}

/** @param array<mixed>|null $args */
function as_has_scheduled_action(string $hook, ?array $args = null, string $group = ''): bool
{
    return false;
}

/** @param array<mixed>|null $args */
function as_next_scheduled_action(string $hook, ?array $args = null, string $group = ''): int|bool
{
    return false;
}

/** @param array<mixed> $args */
function as_unschedule_all_actions(string $hook, array $args = [], string $group = ''): void
{
}
