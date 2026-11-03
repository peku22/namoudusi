<?php

declare(strict_types=1);

namespace Namoudusi\Webinars;

/**
 * WooCommerce logger (WooCommerce → Status → Logs, source "namoudusi-webinars").
 */
final class Log
{
    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $message, array $context = []): void
    {
        wc_get_logger()->error($message, ['source' => 'namoudusi-webinars'] + $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function info(string $message, array $context = []): void
    {
        wc_get_logger()->info($message, ['source' => 'namoudusi-webinars'] + $context);
    }
}
