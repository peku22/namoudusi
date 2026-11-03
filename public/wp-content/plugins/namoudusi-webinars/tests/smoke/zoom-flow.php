<?php

/**
 * Smoke test of Zoom registration (fake client), access/link e-mails (Mailpit) and calendar files.
 * Requires the local environment without Zoom credentials. Creates its own data and removes it.
 *
 * Run: docker compose exec -u www-data web wp eval-file wp-content/plugins/namoudusi-webinars/tests/smoke/zoom-flow.php
 */

// phpcs:ignoreFile

declare(strict_types=1);

use Namoudusi\Webinars\Access\EntitlementRepository;
use Namoudusi\Webinars\Account\WebinarsEndpoint;
use Namoudusi\Webinars\Plugin;
use Namoudusi\Webinars\Product\Webinar;
use Namoudusi\Webinars\Product\WebinarFields;
use Namoudusi\Webinars\Product\ZoomKind;
use Namoudusi\Webinars\Zoom\FakeZoomClient;
use Namoudusi\Webinars\Zoom\ZoomClient;
use Namoudusi\Webinars\Zoom\ZoomClientFactory;
use Namoudusi\Webinars\Zoom\ZoomException;
use Namoudusi\Webinars\Zoom\ZoomRegistrar;
use Namoudusi\Webinars\Zoom\ZoomRegistration;

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    WP_CLI::log(($ok ? '  ok    ' : '  FAIL  ') . $label . ($detail !== '' ? "  ({$detail})" : ''));
    if (!$ok) {
        $failures++;
    }
};

if (!ZoomClientFactory::create() instanceof FakeZoomClient) {
    WP_CLI::error('This test needs the local fake Zoom client (no NAMOUDUSI_ZOOM_* constants).');
}

/** Runs due Action Scheduler jobs of the plugin's group (future retries stay scheduled). */
$runQueue = static function (): int {
    $ran = 0;
    for ($round = 0; $round < 10; $round++) {
        $ids = as_get_scheduled_actions([
            'group' => ZoomRegistrar::GROUP,
            'status' => ActionScheduler_Store::STATUS_PENDING,
            'date' => gmdate('Y-m-d H:i:s'),
            'date_compare' => '<=',
            'per_page' => 50,
        ], 'ids');
        if ($ids === []) {
            break;
        }
        foreach ($ids as $id) {
            ActionScheduler::runner()->process_action((int) $id, 'smoke-test');
            $ran++;
        }
    }
    return $ran;
};

/** Webinar e-mails (not WooCommerce order e-mails) for a recipient, oldest first: [subject, text, html, attachments]. */
$mails = static function (string $to): array {
    $search = wp_remote_get('http://mailpit:8025/api/v1/search?query=' . rawurlencode('to:"' . $to . '"'));
    $result = [];
    foreach (json_decode((string) wp_remote_retrieve_body($search), true)['messages'] ?? [] as $summary) {
        if (!str_starts_with($summary['Subject'], 'Váš přístup k webináři') && !str_starts_with($summary['Subject'], 'Váš odkaz na Zoom')) {
            continue;
        }
        $message = json_decode((string) wp_remote_retrieve_body(wp_remote_get('http://mailpit:8025/api/v1/message/' . $summary['ID'])), true);
        $attachments = [];
        foreach ($message['Attachments'] ?? [] as $part) {
            $content = wp_remote_retrieve_body(wp_remote_get('http://mailpit:8025/api/v1/message/' . $summary['ID'] . '/part/' . $part['PartID']));
            $attachments[$part['FileName']] = (string) $content;
        }
        $result[] = ['subject' => $summary['Subject'], 'text' => (string) ($message['Text'] ?? ''), 'html' => (string) ($message['HTML'] ?? ''), 'attachments' => $attachments];
    }
    return array_reverse($result); // oldest first
};

$repo = new EntitlementRepository();
$created = ['products' => [], 'orders' => [], 'users' => []];
$token = wp_generate_password(6, false, false);

$makeProduct = static function (string $name, array $post) use (&$created): WC_Product_Simple {
    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_regular_price('790');
    $product->set_status('publish');
    $product->save();
    $_POST = $post;
    (new WebinarFields())->save($product);
    $_POST = [];
    $product->save();
    $created['products'][] = $product->get_id();
    return $product;
};
$updateZoomId = static function (WC_Product $product, string $zoomId): void {
    $webinar = Webinar::fromProduct($product);
    $_POST = [
        Webinar::META_TYPE => 'live',
        Webinar::META_STARTS_AT => $webinar->startsAt->setTimezone(wp_timezone())->format('Y-m-d\TH:i'),
        Webinar::META_DURATION => (string) $webinar->durationMinutes,
        Webinar::META_ZOOM_ID => $zoomId,
    ];
    (new WebinarFields())->save($product);
    $_POST = [];
    $product->save();
};
$buy = static function (int $userId, WC_Product $product) use (&$created): WC_Order {
    $order = wc_create_order(['customer_id' => $userId]);
    $order->add_product($product, 1);
    $order->calculate_totals();
    $order->payment_complete();
    $created['orders'][] = $order->get_id();
    return wc_get_order($order->get_id());
};
$makeUser = static function (string $role) use (&$created, $token): array {
    $email = "zoom-{$role}-{$token}@example.test";
    $id = wp_insert_user([
        'user_login' => "nmd_smoke_{$role}_{$token}",
        'user_pass' => wp_generate_password(),
        'user_email' => $email,
        'first_name' => 'Jana',
        'last_name' => 'Nováková',
        'role' => 'customer',
    ]);
    $created['users'][] = $id;
    return [$id, $email];
};

try {
    $start = (new DateTimeImmutable('+7 days', wp_timezone()))->setTime(18, 0);
    $live = $makeProduct("SMOKE Živý se Zoomem {$token}", [
        Webinar::META_TYPE => 'live',
        Webinar::META_STARTS_AT => $start->format('Y-m-d\TH:i'),
        Webinar::META_DURATION => '90',
        Webinar::META_ZOOM_ID => '111 222 3333',
    ]);
    $runQueue(); // product creation counts as "Zoom ID set" – nobody to register yet

    WP_CLI::log('Purchase of a live webinar with Zoom ID');
    [$buyer, $buyerEmail] = $makeUser('buyer');
    $order = $buy($buyer, $live);
    $check('no e-mail before Zoom registration', $mails($buyerEmail) === []);
    $check('registration queued', as_has_scheduled_action(ZoomRegistrar::HOOK_REGISTER, null, ZoomRegistrar::GROUP) || $repo->find($buyer, $live->get_id())?->zoomJoinUrl !== null);
    $runQueue();
    $e = $repo->find($buyer, $live->get_id());
    $check('personal join URL stored', str_starts_with((string) $e?->zoomJoinUrl, 'https://zoom.us/w/1112223333?tk=fake-'), (string) $e?->zoomJoinUrl);
    $check('meeting ID stored with the registration', $e?->zoomMeetingId === '1112223333');
    $mail = $mails($buyerEmail);
    $check('exactly one access e-mail', count($mail) === 1, count($mail) . ' e-mails');
    $check('Czech subject with webinar name', str_contains($mail[0]['subject'] ?? '', 'Váš přístup k webináři SMOKE Živý'), $mail[0]['subject'] ?? '');
    $check('e-mail contains the personal link', str_contains($mail[0]['html'] ?? '', (string) $e?->zoomJoinUrl));
    $ics = array_values($mail[0]['attachments'] ?? [])[0] ?? '';
    $icsName = array_keys($mail[0]['attachments'] ?? [])[0] ?? '';
    $check('.ics attached with a readable name', str_ends_with($icsName, '.ics') && str_contains($icsName, 'smoke'), $icsName);
    $check('.ics has the UTC start and personal link', str_contains($ics, 'DTSTART:' . $start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'))
        && str_contains(str_replace("\r\n ", '', $ics), (string) $e?->zoomJoinUrl));

    WP_CLI::log('Idempotency');
    do_action('woocommerce_order_status_completed', $order->get_id(), $order);
    $runQueue();
    $check('re-running the order sends nothing new', count($mails($buyerEmail)) === 1);

    WP_CLI::log('Zoom ID changes after purchase');
    $oldUrl = (string) $e->zoomJoinUrl;
    $live = wc_get_product($live->get_id());
    $updateZoomId($live, '444 555 6666');
    $runQueue();
    $e = $repo->find($buyer, $live->get_id());
    $check('re-registered for the new meeting', $e?->zoomMeetingId === '4445556666' && $e->zoomJoinUrl !== $oldUrl, (string) $e?->zoomJoinUrl);
    $mail = $mails($buyerEmail);
    $check('link e-mail sent', count($mail) === 2 && str_contains($mail[1]['subject'], 'Váš odkaz na Zoom'), $mail[1]['subject'] ?? '');
    $check('link e-mail has the new link', str_contains($mail[1]['html'] ?? '', (string) $e?->zoomJoinUrl));
    $check('old registration cancelled during re-registration (inline)', $e->zoomRegistrantId !== null);

    WP_CLI::log('Live webinar without Zoom ID yet');
    $later = $makeProduct("SMOKE Živý bez Zoomu {$token}", [
        Webinar::META_TYPE => 'live',
        Webinar::META_STARTS_AT => $start->format('Y-m-d\TH:i'),
        Webinar::META_DURATION => '60',
    ]);
    [$early, $earlyEmail] = $makeUser('early');
    $earlyOrder = $buy($early, $later);
    $mail = $mails($earlyEmail);
    $check('access e-mail right away, link promised', count($mail) === 1 && str_contains($mail[0]['html'], 'Osobní odkaz na Zoom vám pošleme'));
    $check('no join URL yet', $repo->find($early, $later->get_id())?->zoomJoinUrl === null);
    $updateZoomId(wc_get_product($later->get_id()), '777 888 9999');
    $runQueue();
    $e = $repo->find($early, $later->get_id());
    $check('registered once the Zoom ID was added', str_contains((string) $e?->zoomJoinUrl, '7778889999'));
    $mail = $mails($earlyEmail);
    $check('link e-mail follows', count($mail) === 2 && str_contains($mail[1]['subject'], 'Váš odkaz na Zoom'));

    WP_CLI::log('Refund cancels the registration');
    $registrantId = (string) $repo->find($buyer, $live->get_id())?->zoomRegistrantId;
    wc_get_order($order->get_id())->update_status('refunded');
    $e = $repo->find($buyer, $live->get_id());
    $check('Zoom fields cleared on revoke', $e?->zoomJoinUrl === null && $e->zoomRegistrantId === null);
    $runQueue();
    $done = as_get_scheduled_actions([
        'hook' => ZoomRegistrar::HOOK_CANCEL,
        'args' => [ZoomKind::Meeting->value, '4445556666', $registrantId, $buyerEmail],
        'group' => ZoomRegistrar::GROUP,
        'status' => ActionScheduler_Store::STATUS_COMPLETE,
    ], 'ids');
    $check('cancel job ran for the registrant', count($done) === 1);

    WP_CLI::log('Recorded webinar');
    $recorded = $makeProduct("SMOKE Nahraný {$token}", [Webinar::META_TYPE => 'recorded', Webinar::META_BUNNY_VIDEO_ID => '11111111-2222-4333-8444-555555555555']);
    [$viewer, $viewerEmail] = $makeUser('viewer');
    $buy($viewer, $recorded);
    $mail = $mails($viewerEmail);
    $check('access e-mail right away, no calendar', count($mail) === 1 && $mail[0]['attachments'] === []);

    WP_CLI::log('Zoom failures');
    $failing = new class implements ZoomClient {
        public function register(ZoomKind $kind, string $zoomId, string $email, string $firstName, string $lastName): ZoomRegistration
        {
            throw new ZoomException('Simulated outage');
        }
        public function cancel(ZoomKind $kind, string $zoomId, string $registrantId, string $email): void
        {
        }
        public function details(ZoomKind $kind, string $zoomId): array
        {
            return [];
        }
    };
    $inject = static fn () => $failing;
    add_filter('nmd_zoom_client', $inject);
    [$unlucky, $unluckyEmail] = $makeUser('unlucky');
    remove_filter('nmd_zoom_client', $inject); // grant without auto-queue interference
    $access = Plugin::access()->grant($unlucky, $live->get_id(), null, 'manual');
    as_unschedule_all_actions(ZoomRegistrar::HOOK_REGISTER, [$access->id, ZoomRegistrar::NOTIFY_ACCESS, 1], ZoomRegistrar::GROUP);
    add_filter('nmd_zoom_client', $inject);
    $registrar = new ZoomRegistrar($repo);
    $registrar->handleRegister($access->id, ZoomRegistrar::NOTIFY_ACCESS, 1);
    $retry = as_next_scheduled_action(ZoomRegistrar::HOOK_REGISTER, [$access->id, ZoomRegistrar::NOTIFY_ACCESS, 2], ZoomRegistrar::GROUP);
    $check('attempt 1 failed → retry scheduled in ~5 min', is_int($retry) && abs($retry - (time() + 300)) < 60);
    $check('no e-mail while retrying', $mails($unluckyEmail) === []);
    $registrar->handleRegister($access->id, ZoomRegistrar::NOTIFY_ACCESS, 4);
    remove_filter('nmd_zoom_client', $inject);
    as_unschedule_all_actions(ZoomRegistrar::HOOK_REGISTER, [$access->id, ZoomRegistrar::NOTIFY_ACCESS, 2], ZoomRegistrar::GROUP);
    $mail = $mails($unluckyEmail);
    $check('after the last attempt: access e-mail without link', count($mail) === 1 && str_contains($mail[0]['html'], 'Osobní odkaz na Zoom vám pošleme'));

    WP_CLI::log('Calendar download');
    $cookies = static fn (int $id): array => [
        AUTH_COOKIE => wp_generate_auth_cookie($id, time() + 3600, 'auth'),
        LOGGED_IN_COOKIE => wp_generate_auth_cookie($id, time() + 3600, 'logged_in'),
    ];
    $response = wp_remote_get(WebinarsEndpoint::calendarUrl($later->get_id()), ['cookies' => $cookies($early), 'redirection' => 0]);
    $check('buyer gets text/calendar', wp_remote_retrieve_response_code($response) === 200
        && str_contains((string) wp_remote_retrieve_header($response, 'content-type'), 'text/calendar')
        && str_contains(wp_remote_retrieve_body($response), 'BEGIN:VEVENT'));
    $response = wp_remote_get(WebinarsEndpoint::calendarUrl($later->get_id()), ['cookies' => $cookies($viewer), 'redirection' => 0]);
    $check('non-buyer gets 403', wp_remote_retrieve_response_code($response) === 403, (string) wp_remote_retrieve_response_code($response));
    $response = wp_remote_get(WebinarsEndpoint::url($later->get_id()), ['cookies' => $cookies($early)]);
    $check('webinar page shows join button + calendar link', str_contains(wp_remote_retrieve_body($response), '7778889999') && str_contains(wp_remote_retrieve_body($response), 'kalendar.ics'));

    WP_CLI::log('Admin');
    ob_start();
    (new Namoudusi\Webinars\Admin\AccessAdmin(Plugin::access(), $repo))->renderOrderMetaBox(wc_get_order($earlyOrder->get_id()));
    $html = (string) ob_get_clean();
    $check('order box shows Zoom status', str_contains($html, 'Zoom: zaregistrován'));
    $out = WP_CLI::runcommand('nmd zoom check 1112223333', ['return' => 'all', 'exit_error' => false]);
    $check('wp nmd zoom check works (fake)', $out->return_code === 0 && str_contains($out->stdout . $out->stderr, 'Registration enabled'));
} finally {
    WP_CLI::log('Cleanup');
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($created['orders'] as $id) {
        $o = wc_get_order($id);
        foreach ($o ? $o->get_refunds() : [] as $refund) {
            $refund->delete(true);
        }
        $o?->delete(true);
    }
    foreach ($created['users'] as $id) {
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nmd_entitlements WHERE user_id = %d", $id));
        foreach ($ids as $entitlementId) {
            foreach ([ZoomRegistrar::NOTIFY_ACCESS, ZoomRegistrar::NOTIFY_LINK, ZoomRegistrar::NOTIFY_NONE] as $notify) {
                for ($attempt = 1; $attempt <= 4; $attempt++) {
                    as_unschedule_all_actions(ZoomRegistrar::HOOK_REGISTER, [(int) $entitlementId, $notify, $attempt], ZoomRegistrar::GROUP);
                }
            }
        }
        $wpdb->delete($wpdb->prefix . 'nmd_entitlements', ['user_id' => $id]);
        wp_delete_user($id);
    }
    foreach ($created['products'] as $id) {
        as_unschedule_all_actions(ZoomRegistrar::HOOK_SYNC_PRODUCT, [$id], ZoomRegistrar::GROUP);
        wc_get_product($id)?->delete(true);
    }
}

$failures === 0 ? WP_CLI::success('All checks passed.') : WP_CLI::error("{$failures} check(s) failed.");
