<?php

/**
 * End-to-end smoke test of webinar access against the local site. Creates its own data and removes it.
 *
 * Run: docker compose exec -u www-data web wp eval-file wp-content/plugins/namoudusi-webinars/tests/smoke/access-flow.php
 */

// phpcs:ignoreFile

declare(strict_types=1);

use Namoudusi\Webinars\Access\Clock;
use Namoudusi\Webinars\Access\EntitlementRepository;
use Namoudusi\Webinars\Plugin;
use Namoudusi\Webinars\Product\Webinar;
use Namoudusi\Webinars\Product\WebinarFields;

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    WP_CLI::log(($ok ? '  ok    ' : '  FAIL  ') . $label . ($detail !== '' ? "  ({$detail})" : ''));
    if (!$ok) {
        $failures++;
    }
};
$fmt = static fn (?DateTimeImmutable $d): string => $d?->format('Y-m-d H:i') ?? 'unlimited';
$closeTo = static fn (?DateTimeImmutable $a, ?DateTimeImmutable $b): bool =>
    $a !== null && $b !== null && abs($a->getTimestamp() - $b->getTimestamp()) < 120;

$repo = new EntitlementRepository();
$created = ['products' => [], 'orders' => [], 'users' => []];

/** Saves a product through the real admin save handler, as the product form would. */
$makeProduct = static function (string $name, array $post) use (&$created): WC_Product_Simple {
    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_regular_price('990');
    $product->set_status('publish');
    $_POST = $post;
    (new WebinarFields())->save($product);
    $_POST = [];
    $product->save();
    $created['products'][] = $product->get_id();
    return $product;
};

$makeOrder = static function (int $userId, array $products) use (&$created): WC_Order {
    $order = wc_create_order(['customer_id' => $userId]);
    foreach ($products as $product) {
        $order->add_product($product, 1);
    }
    $order->calculate_totals();
    $order->save();
    $created['orders'][] = $order->get_id();
    return $order;
};

try {
    WP_CLI::log('Setup');
    $userId = wp_create_user('nmd_smoke_' . wp_generate_password(6, false), wp_generate_password(), 'smoke-' . time() . '@example.test');
    $created['users'][] = $userId;

    $recorded = $makeProduct('SMOKE recorded 30d', [
        Webinar::META_TYPE => 'recorded',
        Webinar::META_BUNNY_VIDEO_ID => 'abc-123',
        Webinar::META_ACCESS_DAYS => '30',
    ]);
    $startLocal = (new DateTimeImmutable('+10 days', wp_timezone()))->setTime(18, 0);
    $live = $makeProduct('SMOKE live 30d', [
        Webinar::META_TYPE => 'live',
        Webinar::META_STARTS_AT => $startLocal->format('Y-m-d\TH:i'),
        Webinar::META_DURATION => '90',
        Webinar::META_ZOOM_ID => '123 4567 8901',
        Webinar::META_ACCESS_DAYS => '30',
    ]);
    $unlimited = $makeProduct('SMOKE recorded unlimited', [
        Webinar::META_TYPE => 'recorded',
        Webinar::META_BUNNY_VIDEO_ID => 'def-456',
    ]);
    $plain = $makeProduct('SMOKE plain product', []);

    WP_CLI::log('Product fields');
    $liveWebinar = Webinar::fromProductId($live->get_id());
    $check('webinar products are virtual + sold individually', $recorded->is_virtual() && $recorded->is_sold_individually());
    $check('live start stored in UTC', $liveWebinar?->startsAt == $startLocal->setTimezone(new DateTimeZone('UTC')), $fmt($liveWebinar?->startsAt));
    $check('zoom id normalized to digits', $liveWebinar?->zoomId === '12345678901', (string) $liveWebinar?->zoomId);
    $check('empty access days = unlimited', Webinar::fromProductId($unlimited->get_id())?->accessDays === null);
    $check('plain product is not a webinar', Webinar::fromProductId($plain->get_id()) === null && !$plain->is_virtual());

    WP_CLI::log('Purchase → access');
    $order = $makeOrder($userId, [$recorded, $live, $unlimited]);
    $order->payment_complete();
    $order = wc_get_order($order->get_id());
    $check('webinar-only order auto-completed', $order->get_status() === 'completed', $order->get_status());

    $e = $repo->find($userId, $recorded->get_id());
    $check('recorded: expires purchase + 30 days', $closeTo($e?->expiresAt, Clock::now()->modify('+30 days')), $fmt($e?->expiresAt));
    $e = $repo->find($userId, $live->get_id());
    $check('live: expires event start + 30 days', $e?->expiresAt == $liveWebinar->startsAt->modify('+30 days'), $fmt($e?->expiresAt));
    $e = $repo->find($userId, $unlimited->get_id());
    $check('unlimited: no expiry', $e !== null && $e->expiresAt === null);
    $check('access check true for buyer', Plugin::access()->hasAccess($userId, $recorded->get_id()));
    $check('access check false for others', !Plugin::access()->hasAccess(1, $recorded->get_id()));
    $items = array_values($order->get_items());
    $check('terms snapshotted on order line', $items[0]->get_meta('_nmd_access_days') === '30' && $items[2]->get_meta('_nmd_access_days') === 'unlimited');

    WP_CLI::log('Snapshot protects sold terms');
    $recorded->update_meta_data(Webinar::META_ACCESS_DAYS, '5');
    $recorded->save();
    do_action('woocommerce_order_status_completed', $order->get_id(), $order); // re-run grant
    $e = $repo->find($userId, $recorded->get_id());
    $check('product change does not shorten sold access', $closeTo($e?->expiresAt, Clock::now()->modify('+30 days')), $fmt($e?->expiresAt));
    $recorded->update_meta_data(Webinar::META_ACCESS_DAYS, '30');
    $recorded->save();

    WP_CLI::log('Partial refund');
    $recordedItem = $items[0];
    wc_create_refund([
        'order_id' => $order->get_id(),
        'amount' => $recordedItem->get_total(),
        'line_items' => [$recordedItem->get_id() => ['qty' => 1, 'refund_total' => $recordedItem->get_total()]],
    ]);
    $check('refunded line revoked', $repo->find($userId, $recorded->get_id())?->revokedAt !== null);
    $check('other lines still active', Plugin::access()->hasAccess($userId, $live->get_id()));

    WP_CLI::log('Repeat purchase');
    $order2 = $makeOrder($userId, [$recorded]);
    $order2->payment_complete();
    $e = $repo->find($userId, $recorded->get_id());
    $check('re-purchase reactivates revoked access', $e?->isActive(Clock::now()) === true && $e->orderId === $order2->get_id());
    $check('one record per user + product', count($repo->all($userId, $recorded->get_id())) === 1);

    WP_CLI::log('Full refund / cancel');
    $order->update_status('refunded');
    $check('full refund revokes live', $repo->find($userId, $live->get_id())?->revokedAt !== null);
    $check('full refund revokes unlimited', $repo->find($userId, $unlimited->get_id())?->revokedAt !== null);
    $check('access from other order kept', Plugin::access()->hasAccess($userId, $recorded->get_id()));
    $order2->update_status('cancelled');
    $check('cancel revokes', !Plugin::access()->hasAccess($userId, $recorded->get_id()));

    WP_CLI::log('Manual grant');
    $until = (new DateTimeImmutable('+7 days', wp_timezone()))->format('Y-m-d');
    WP_CLI::runcommand("nmd access grant {$userId} {$live->get_id()} --until={$until}", ['return' => true]);
    $e = $repo->find($userId, $live->get_id());
    $check('manual grant active until end of day', $e?->isActive(Clock::now()) === true
        && $e->source === 'manual'
        && $e->expiresAt?->setTimezone(wp_timezone())->format('Y-m-d H:i:s') === "{$until} 23:59:59", $fmt($e?->expiresAt));
    WP_CLI::runcommand("nmd access grant {$userId} {$live->get_id()}", ['return' => true]);
    $check('manual grant replaces expiry (unlimited)', $repo->find($userId, $live->get_id())?->expiresAt === null);

    WP_CLI::log('Non-webinar order');
    $order3 = $makeOrder($userId, [$plain]);
    $order3->payment_complete();
    $check('plain virtual order not forced to completed', wc_get_order($order3->get_id())->get_status() === 'processing', wc_get_order($order3->get_id())->get_status());

    WP_CLI::log('Admin screens');
    // Admin classes are only registered in wp-admin, and Woo's form helpers live in an admin include.
    require_once WC()->plugin_path() . '/includes/admin/wc-meta-box-functions.php';
    $admin = new Namoudusi\Webinars\Admin\AccessAdmin(Plugin::access(), $repo);
    wp_set_current_user(1);
    ob_start();
    $admin->renderUserSection(get_user_by('id', $userId));
    $html = (string) ob_get_clean();
    $check('user profile shows access table + grant form', str_contains($html, 'SMOKE live 30d') && str_contains($html, 'nmd_grant_product'));
    ob_start();
    $admin->renderOrderMetaBox(wc_get_order($order->get_id()));
    $html = (string) ob_get_clean();
    // Records move to the latest grant (repeat purchase, manual grant), so count what the order still holds.
    $expected = count($repo->forOrder($order->get_id()));
    $check('order meta box lists access', $expected > 0 && substr_count($html, '<li>') === $expected, "{$expected} expected");
    ob_start();
    $GLOBALS['product_object'] = wc_get_product($live->get_id());
    (new WebinarFields())->renderPanel();
    $html = (string) ob_get_clean();
    $check('product tab renders saved values', str_contains($html, '12345678901') && str_contains($html, $startLocal->format('Y-m-d\TH:i')));
} finally {
    WP_CLI::log('Cleanup');
    global $wpdb;
    foreach ($created['orders'] as $id) {
        $o = wc_get_order($id);
        foreach ($o ? $o->get_refunds() : [] as $refund) {
            $refund->delete(true);
        }
        $o?->delete(true);
    }
    foreach ($created['users'] as $id) {
        $wpdb->delete($wpdb->prefix . 'nmd_entitlements', ['user_id' => $id]);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($id);
    }
    foreach ($created['products'] as $id) {
        wc_get_product($id)?->delete(true);
    }
}

$failures === 0 ? WP_CLI::success('All checks passed.') : WP_CLI::error("{$failures} check(s) failed.");
