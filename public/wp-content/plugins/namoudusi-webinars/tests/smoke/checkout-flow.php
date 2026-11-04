<?php

/**
 * Smoke test of the block checkout consent (immediate delivery of digital content) over real Store API requests
 * as a logged-in customer. Temporarily enables the bank-transfer gateway. Creates its own data and removes it.
 *
 * Run: docker compose exec -u www-data web wp eval-file wp-content/plugins/namoudusi-webinars/tests/smoke/checkout-flow.php
 */

// phpcs:ignoreFile

declare(strict_types=1);

use Namoudusi\Webinars\Checkout\DigitalConsent;
use Namoudusi\Webinars\Product\Webinar;

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    WP_CLI::log(($ok ? '  ok    ' : '  FAIL  ') . $label . ($detail !== '' ? "  ({$detail})" : ''));
    if (!$ok) {
        $failures++;
    }
};

/**
 * Minimal browser: keeps cookies (login + WooCommerce session) and the Store API nonce between requests.
 */
final class SmokeBrowser
{
    /** @var array<string, string> */
    private array $cookies = [];
    private string $nonce = '';

    public function __construct(int $userId)
    {
        $expiration = time() + HOUR_IN_SECONDS;
        $this->cookies[AUTH_COOKIE] = wp_generate_auth_cookie($userId, $expiration, 'auth');
        $this->cookies[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($userId, $expiration, 'logged_in');
    }

    /** @return array{0: int, 1: mixed, 2: string} status, decoded JSON, raw body */
    public function request(string $method, string $url, ?array $json = null): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($this->nonce !== '') {
            $headers['Nonce'] = $this->nonce;
        }
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $response = wp_remote_request($url, [
            'method' => $method,
            'headers' => $headers,
            'body' => $json !== null ? wp_json_encode($json) : null,
            'cookies' => $this->cookies,
            'redirection' => 0,
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) {
            return [0, null, $response->get_error_message()];
        }
        foreach (wp_remote_retrieve_cookies($response) as $cookie) {
            $this->cookies[$cookie->name] = $cookie->value;
        }
        $nonce = wp_remote_retrieve_header($response, 'nonce');
        if (is_string($nonce) && $nonce !== '') {
            $this->nonce = $nonce;
        }
        $body = (string) wp_remote_retrieve_body($response);
        return [(int) wp_remote_retrieve_response_code($response), json_decode($body, true), $body];
    }

    public function store(string $method, string $path, ?array $json = null): array
    {
        return $this->request($method, rest_url('wc/store/v1/' . $path), $json);
    }
}

$created = ['products' => [], 'orders' => [], 'users' => []];
$token = wp_generate_password(6, false, false);
$bacsOption = get_option('woocommerce_bacs_settings', null);

$billing = [
    'first_name' => 'Eva',
    'last_name' => 'Svobodová',
    'address_1' => 'Vinohradská 1',
    'city' => 'Praha',
    'postcode' => '120 00',
    'country' => 'CZ',
    'email' => "checkout-{$token}@example.test",
    'phone' => '',
];
$checkout = static fn (SmokeBrowser $browser, ?bool $consent) => $browser->store('POST', 'checkout', array_filter([
    'billing_address' => $billing,
    'payment_method' => 'bacs',
    'additional_fields' => $consent === null ? null : [DigitalConsent::FIELD_ID => $consent],
], static fn ($v) => $v !== null));
$hasWebinarFlag = static fn ($cart): ?bool => is_array($cart) ? ($cart['extensions']['namoudusi']['has_webinar'] ?? null) : null;

try {
    WP_CLI::log('Setup');
    update_option('woocommerce_bacs_settings', array_merge(is_array($bacsOption) ? $bacsOption : [], ['enabled' => 'yes']));
    $userId = wp_insert_user([
        'user_login' => "nmd_smoke_checkout_{$token}",
        'user_pass' => wp_generate_password(),
        'user_email' => $billing['email'],
        'role' => 'customer',
    ]);
    $created['users'][] = $userId;

    $webinar = new WC_Product_Simple();
    $webinar->set_name("SMOKE Checkout webinář {$token}");
    $webinar->set_regular_price('490');
    $webinar->set_status('publish');
    $webinar->set_virtual(true);
    $webinar->set_sold_individually(true);
    $webinar->update_meta_data(Webinar::META_TYPE, 'recorded');
    $webinar->update_meta_data(Webinar::META_BUNNY_VIDEO_ID, '11111111-2222-4333-8444-555555555555');
    $webinar->save();
    $plain = new WC_Product_Simple();
    $plain->set_name("SMOKE Checkout dárkový poukaz {$token}");
    $plain->set_regular_price('300');
    $plain->set_status('publish');
    $plain->set_virtual(true);
    $plain->save();
    $created['products'] = [$webinar->get_id(), $plain->get_id()];

    $browser = new SmokeBrowser($userId);

    WP_CLI::log('Cart with a webinar');
    [$status, $cart] = $browser->store('GET', 'cart');
    $check('empty cart: has_webinar false', $status === 200 && $hasWebinarFlag($cart) === false, wp_json_encode($cart['extensions'] ?? null));
    [$status, $cart] = $browser->store('POST', 'cart/add-item', ['id' => $webinar->get_id(), 'quantity' => 1]);
    $check('webinar added: has_webinar true', $status === 201 && $hasWebinarFlag($cart) === true, (string) $status);

    [$status, , $html] = $browser->request('GET', wc_get_checkout_url());
    $check('checkout page is the block checkout', $status === 200 && str_contains($html, 'wp-block-woocommerce-checkout'), (string) $status);
    // wcSettings are embedded as URL-encoded JSON; find our field definition in it.
    $field = null;
    if (preg_match_all("/decodeURIComponent\\(\\s*'([^']+)'\\s*\\)/", $html, $chunks)) {
        foreach ($chunks[1] as $chunk) {
            $settings = json_decode(rawurldecode($chunk), true);
            $stack = [$settings];
            while ($stack !== [] && $field === null) {
                $node = array_pop($stack);
                if (!is_array($node)) {
                    continue;
                }
                if (($node['id'] ?? null) === DigitalConsent::FIELD_ID && isset($node['label'])) {
                    $field = $node;
                }
                foreach ($node as $child) {
                    $stack[] = $child;
                }
            }
        }
    }
    $check('field sent to the client with the Czech wording', is_array($field) && str_contains((string) $field['label'], 'ztrácím právo odstoupit'), (string) ($field['label'] ?? 'not found'));
    $check('…with the cart rules and our error message', is_array($field) && is_array($field['required'] ?? null) && is_array($field['hidden'] ?? null)
        && str_contains((string) ($field['errorMessage'] ?? ''), 'Potvrďte prosím souhlas'), (string) ($field['errorMessage'] ?? ''));

    [$status, $body] = $checkout($browser, null);
    $check('checkout without the consent fails', $status === 400, $status . ' ' . ($body['message'] ?? ''));
    $check('…on our field', ($body['data']['details']['additional_fields']['data']['key'] ?? '') === DigitalConsent::FIELD_ID);
    [$status, $body] = $checkout($browser, false);
    $check('checkout with the box unticked fails', $status === 400, $status . ' ' . ($body['message'] ?? ''));
    if (isset($body['data']['order_id'])) {
        $created['orders'][] = (int) $body['data']['order_id'];
    }

    $before = time();
    [$status, $body] = $checkout($browser, true);
    $orderId = (int) ($body['order_id'] ?? 0);
    if ($orderId > 0) {
        $created['orders'][] = $orderId;
    }
    $check('checkout with the consent succeeds', $status === 200 && $orderId > 0, $status . ' ' . ($body['message'] ?? ''));
    $order = wc_get_order($orderId);
    $consent = $order instanceof WC_Order ? DigitalConsent::forOrder($order) : null;
    $check('order stores the field value (WooCommerce)', $order instanceof WC_Order && (bool) $order->get_meta(DigitalConsent::META_VALUE));
    $check('order stores the consent time', $consent !== null && abs($consent['givenAt']->getTimestamp() - $before) < 60, $consent['givenAt']?->format('Y-m-d H:i:s') ?? '');
    $check('order stores the Czech wording the customer saw', $consent !== null && str_contains($consent['text'], 'ztrácím právo odstoupit od smlouvy'), $consent['text'] ?? '');
    $notes = $order instanceof WC_Order ? wc_get_order_notes(['order_id' => $orderId]) : [];
    $check('order note records the consent', array_filter($notes, static fn ($n) => str_contains($n->content, 'souhlasil')) !== []);
    $check('order belongs to the customer', $order instanceof WC_Order && $order->get_customer_id() === $userId);

    WP_CLI::log('Admin and customer views');
    require_once WC()->plugin_path() . '/includes/admin/wc-meta-box-functions.php';
    $admin = new Namoudusi\Webinars\Admin\AccessAdmin(Namoudusi\Webinars\Plugin::access(), new Namoudusi\Webinars\Access\EntitlementRepository());
    ob_start();
    $admin->renderOrderMetaBox($order);
    $html = (string) ob_get_clean();
    $check('order box: consent given with time', str_contains($html, 'Souhlas se zpřístupněním ihned') && str_contains($html, 'udělen'));
    [$status, , $html] = $browser->request('GET', $order->get_checkout_order_received_url());
    $check('order confirmation page shows the consent', $status === 200 && str_contains($html, 'ztrácím právo odstoupit'), (string) $status);

    WP_CLI::log('Cart without webinars');
    [$status, $cart] = $browser->store('POST', 'cart/add-item', ['id' => $plain->get_id(), 'quantity' => 1]);
    $check('plain product: has_webinar false', $status === 201 && $hasWebinarFlag($cart) === false);
    [$status, $body] = $checkout($browser, null);
    $plainOrderId = (int) ($body['order_id'] ?? 0);
    if ($plainOrderId > 0) {
        $created['orders'][] = $plainOrderId;
    }
    $check('checkout without the consent succeeds', $status === 200 && $plainOrderId > 0, $status . ' ' . ($body['message'] ?? ''));
    $check('…and stores no consent', $plainOrderId > 0 && DigitalConsent::forOrder(wc_get_order($plainOrderId)) === null);

    WP_CLI::log('Order created in the admin');
    $manual = wc_create_order(['customer_id' => $userId]);
    $manual->add_product($webinar, 1);
    $manual->calculate_totals();
    $created['orders'][] = $manual->get_id();
    ob_start();
    $admin->renderOrderMetaBox($manual);
    $html = (string) ob_get_clean();
    $check('order box: consent not given, right of withdrawal kept', str_contains($html, 'neudělen'));
} finally {
    WP_CLI::log('Cleanup');
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach (array_unique($created['orders']) as $id) {
        wc_get_order($id)?->delete(true);
    }
    foreach ($created['users'] as $id) {
        $wpdb->delete($wpdb->prefix . 'nmd_entitlements', ['user_id' => $id]);
        wp_delete_user($id);
    }
    foreach ($created['products'] as $id) {
        wc_get_product($id)?->delete(true);
    }
    $bacsOption === null ? delete_option('woocommerce_bacs_settings') : update_option('woocommerce_bacs_settings', $bacsOption);
}

$failures === 0 ? WP_CLI::success('All checks passed.') : WP_CLI::error("{$failures} check(s) failed.");
