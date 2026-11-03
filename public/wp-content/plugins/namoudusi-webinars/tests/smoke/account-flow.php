<?php

/**
 * Smoke test of My Account → My webinars, document downloads and the product page, over real HTTP requests.
 * Creates its own data and removes it.
 *
 * Run: docker compose exec -u www-data web wp eval-file wp-content/plugins/namoudusi-webinars/tests/smoke/account-flow.php
 */

// phpcs:ignoreFile

declare(strict_types=1);

use Namoudusi\Webinars\Access\Entitlement;
use Namoudusi\Webinars\Access\EntitlementRepository;
use Namoudusi\Webinars\Account\WebinarsEndpoint;
use Namoudusi\Webinars\Documents\DocumentStorage;
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

/** GET as the given user (0 = anonymous); returns [status, body, headers]. */
$get = static function (string $url, int $userId = 0): array {
    $cookies = [];
    if ($userId > 0) {
        $expiration = time() + HOUR_IN_SECONDS;
        $cookies[AUTH_COOKIE] = wp_generate_auth_cookie($userId, $expiration, 'auth');
        $cookies[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($userId, $expiration, 'logged_in');
    }
    $response = wp_remote_get($url, ['cookies' => $cookies, 'redirection' => 0, 'timeout' => 20]);
    if (is_wp_error($response)) {
        return [0, $response->get_error_message(), []];
    }
    return [
        (int) wp_remote_retrieve_response_code($response),
        (string) wp_remote_retrieve_body($response),
        wp_remote_retrieve_headers($response),
    ];
};

$repo = new EntitlementRepository();
$created = ['products' => [], 'users' => []];

try {
    WP_CLI::log('Setup');
    $buyer = wp_create_user('nmd_smoke_buyer_' . wp_generate_password(4, false), wp_generate_password(), 'buyer-' . time() . '@example.test');
    $other = wp_create_user('nmd_smoke_other_' . wp_generate_password(4, false), wp_generate_password(), 'other-' . time() . '@example.test');
    $created['users'] = [$buyer, $other];

    $makeProduct = static function (string $name, array $post) use (&$created): WC_Product_Simple {
        $product = new WC_Product_Simple();
        $product->set_name($name);
        $product->set_regular_price('990');
        $product->set_status('publish');
        $product->save();
        $_POST = $post;
        (new WebinarFields())->save($product);
        $_POST = [];
        $product->save();
        $created['products'][] = $product->get_id();
        return $product;
    };
    $recorded = $makeProduct('SMOKE Nahraný webinář', [Webinar::META_TYPE => 'recorded', Webinar::META_BUNNY_VIDEO_ID => 'abc']);
    $live = $makeProduct('SMOKE Živý webinář', [
        Webinar::META_TYPE => 'live',
        Webinar::META_STARTS_AT => (new DateTimeImmutable('+5 days', wp_timezone()))->format('Y-m-d\T18:00'),
        Webinar::META_DURATION => '90',
    ]);

    // Attach a document the way an upload would, but via sideload (no HTTP upload in CLI).
    $pdfContent = "%PDF-1.4\n% smoke test " . wp_generate_password(12, false) . "\n";
    $tmp = wp_tempnam('handout.pdf');
    file_put_contents($tmp, $pdfContent);
    $document = DocumentStorage::store(
        ['name' => 'handout.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp, 'error' => 0, 'size' => strlen($pdfContent)],
        $recorded->get_id(),
        'Pracovní list',
        sideload: true
    );
    $check('document stored', $document instanceof Namoudusi\Webinars\Documents\Document, is_string($document) ? $document : $document->file);
    $recorded->update_meta_data(Webinar::META_DOCUMENTS, [$document->toArray()]);
    $recorded->save();
    $documentPath = DocumentStorage::path($document);
    $check('file is inside the protected folder', $documentPath !== null && str_contains($documentPath, '/namoudusi-private/'));

    Plugin::access()->grant($buyer, $recorded->get_id(), null, Entitlement::SOURCE_MANUAL);
    $liveAccess = Plugin::access()->grant($buyer, $live->get_id(), null, Entitlement::SOURCE_MANUAL);
    $repo->update($liveAccess->id, ['zoom_join_url' => 'https://zoom.us/w/123?tk=smoke-personal']);

    $list = WebinarsEndpoint::url();
    $detail = WebinarsEndpoint::url($recorded->get_id());
    $liveDetail = WebinarsEndpoint::url($live->get_id());
    $download = WebinarsEndpoint::url($recorded->get_id(), $document->id);

    WP_CLI::log('Buyer');
    [$status, $body] = $get($list, $buyer);
    $check('list 200', $status === 200, (string) $status);
    $check('menu item "Moje webináře"', str_contains($body, 'Moje webináře'));
    $check('list shows both webinars, live first', ($a = strpos($body, 'SMOKE Živý')) !== false && ($b = strpos($body, 'SMOKE Nahraný')) !== false && $a < $b);
    [$status, $body] = $get($detail, $buyer);
    $check('detail 200 with document link', $status === 200 && str_contains($body, 'Pracovní list') && str_contains($body, $document->id));
    [$status, $body] = $get($liveDetail, $buyer);
    $check('live detail shows personal Zoom link', $status === 200 && str_contains($body, 'tk=smoke-personal'));
    [$status, $body, $headers] = $get($download, $buyer);
    $check('download 200 with the file', $status === 200 && $body === $pdfContent, (string) $status);
    $check('download is an attachment named after the title', str_contains((string) ($headers['content-disposition'] ?? ''), 'Pracovni-list.pdf') || str_contains((string) ($headers['content-disposition'] ?? ''), '.pdf'), (string) ($headers['content-disposition'] ?? ''));
    $check('download not cacheable / not indexable', str_contains((string) ($headers['cache-control'] ?? ''), 'no-') && str_contains((string) ($headers['x-robots-tag'] ?? ''), 'noindex'));
    [$status, $body] = $get((string) get_permalink($recorded->get_id()), $buyer);
    $check('product page: "Přejít na webinář", no add to cart', str_contains($body, 'Přejít na webinář') && !str_contains($body, 'name="add-to-cart"'));
    [$status] = $get(WebinarsEndpoint::url($recorded->get_id(), wp_generate_uuid4()), $buyer);
    $check('unknown document 404', $status === 404, (string) $status);

    WP_CLI::log('Customer without access');
    [$status, $body] = $get($detail, $other);
    $check('detail shows "no access", no document link', str_contains($body, 'K tomuto webináři nemáte přístup') && !str_contains($body, $document->id));
    [$status, $body] = $get($download, $other);
    $check('download 403', $status === 403 && $body !== $pdfContent, (string) $status);
    [$status, $body] = $get((string) get_permalink($recorded->get_id()), $other);
    $check('product page: add to cart available', str_contains($body, 'name="add-to-cart"'));

    WP_CLI::log('Anonymous visitor');
    [$status, $body, $headers] = $get($download);
    $check('download redirects to login', $status === 302 && str_contains((string) ($headers['location'] ?? ''), 'muj-ucet'), $status . ' ' . ($headers['location'] ?? ''));
    $directUrl = wp_upload_dir(null, false)['baseurl'] . '/' . DocumentStorage::DIRECTORY . '/' . $document->file;
    [$status, $body] = $get($directUrl);
    $check('direct file URL denied by Apache', $status === 403 && $body !== $pdfContent, (string) $status);

    WP_CLI::log('Expired access');
    $repo->update($repo->find($buyer, $recorded->get_id())->id, ['expires_at' => new DateTimeImmutable('-1 day', new DateTimeZone('UTC'))]);
    [$status, $body] = $get($list, $buyer);
    $check('list marks it expired with "Koupit znovu"', str_contains($body, 'Přístup vypršel') && str_contains($body, 'Koupit znovu'));
    [$status] = $get($download, $buyer);
    $check('download 403 after expiry', $status === 403, (string) $status);
    [$status, $body] = $get((string) get_permalink($recorded->get_id()), $buyer);
    $check('product page: can buy again', str_contains($body, 'name="add-to-cart"'));
} finally {
    WP_CLI::log('Cleanup');
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($created['users'] as $id) {
        $wpdb->delete($wpdb->prefix . 'nmd_entitlements', ['user_id' => $id]);
        wp_delete_user($id);
    }
    foreach ($created['products'] as $id) {
        wc_get_product($id)?->delete(true);
    }
    $dirsLeft = array_filter($created['products'], static fn (int $id): bool => is_dir(DocumentStorage::baseDir() . '/' . $id));
    $check('product documents removed with the product', $dirsLeft === []);
}

$failures === 0 ? WP_CLI::success('All checks passed.') : WP_CLI::error("{$failures} check(s) failed.");
