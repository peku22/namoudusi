<?php

declare(strict_types=1);

namespace Namoudusi\Webinars;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Namoudusi\Webinars\Database\Migrator;

final class Plugin
{
    public const TEXT_DOMAIN = 'namoudusi-webinars';

    public static function declareWooCompatibility(): void
    {
        if (class_exists(FeaturesUtil::class)) {
            FeaturesUtil::declare_compatibility('custom_order_tables', NMD_WEBINARS_FILE, true);
            FeaturesUtil::declare_compatibility('cart_checkout_blocks', NMD_WEBINARS_FILE, true);
        }
    }

    public static function boot(): void
    {
        // Without WooCommerce the plugin stays inert instead of breaking the site.
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [self::class, 'missingWooCommerceNotice']);
            return;
        }

        Migrator::migrateIfNeeded();

        add_action('init', [self::class, 'loadTextDomain']);
        add_action('admin_notices', [self::class, 'configNotice']);
    }

    public static function loadTextDomain(): void
    {
        load_plugin_textdomain(self::TEXT_DOMAIN, false, dirname(plugin_basename(NMD_WEBINARS_FILE)) . '/languages');
    }

    public static function missingWooCommerceNotice(): void
    {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Na mou duši – Webináře requires WooCommerce to be active.', 'namoudusi-webinars')
        );
    }

    public static function configNotice(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $missing = Config::missingConstants();
        if ($missing === [] || wp_get_environment_type() === 'local') {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p>%s <code>%s</code></p></div>',
            esc_html__('Na mou duši – Webináře: missing wp-config.php constants:', 'namoudusi-webinars'),
            esc_html(implode(', ', $missing))
        );
    }
}
