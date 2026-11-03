<?php

/**
 * Plugin Name:       Na mou duši – Webináře
 * Description:       Recorded (Bunny Stream) and live (Zoom) webinars sold via WooCommerce, access in My Account.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Requires Plugins:  woocommerce
 * Author:            Na mou duši
 * Text Domain:       namoudusi-webinars
 * Domain Path:       /languages
 *
 * WC requires at least: 9.0
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

const NMD_WEBINARS_VERSION = '0.1.0';
const NMD_WEBINARS_FILE = __FILE__;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Namoudusi\\Webinars\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

register_activation_hook(__FILE__, [Namoudusi\Webinars\Plugin::class, 'activate']);

add_action('before_woocommerce_init', [Namoudusi\Webinars\Plugin::class, 'declareWooCompatibility']);
add_action('plugins_loaded', [Namoudusi\Webinars\Plugin::class, 'boot']);
