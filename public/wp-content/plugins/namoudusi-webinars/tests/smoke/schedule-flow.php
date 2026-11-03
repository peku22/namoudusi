<?php

/**
 * Smoke test of live-webinar reminders (24 h / 1 h), start-time changes (new expiry, "new date" e-mail,
 * rescheduled reminders) over the real Action Scheduler queue and Mailpit. Uses the local fake Zoom client.
 * Creates its own data and removes it.
 *
 * Run: docker compose exec -u www-data web wp eval-file wp-content/plugins/namoudusi-webinars/tests/smoke/schedule-flow.php
 */

// phpcs:ignoreFile

declare(strict_types=1);

use Namoudusi\Webinars\Access\AccessTerms;
use Namoudusi\Webinars\Access\Clock;
use Namoudusi\Webinars\Access\EntitlementRepository;
use Namoudusi\Webinars\Plugin;
use Namoudusi\Webinars\Product\Webinar;
use Namoudusi\Webinars\Product\WebinarFields;
use Namoudusi\Webinars\Schedule\Reminders;
use Namoudusi\Webinars\Schedule\StartChange;
use Namoudusi\Webinars\Zoom\FakeZoomClient;
use Namoudusi\Webinars\Zoom\ZoomClientFactory;
use Namoudusi\Webinars\Zoom\ZoomRegistrar;

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

/** Runs due jobs of the plugin's group (future reminders stay scheduled). */
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

$subjects = ['Váš přístup k webináři', 'Připomínka:', 'Nový termín:'];
/** Webinar e-mails for a recipient, oldest first: [subject, html, attachments]. */
$mails = static function (string $to) use ($subjects): array {
    $search = wp_remote_get('http://mailpit:8025/api/v1/search?query=' . rawurlencode('to:"' . $to . '"'));
    $result = [];
    foreach (json_decode((string) wp_remote_retrieve_body($search), true)['messages'] ?? [] as $summary) {
        $ours = array_filter($subjects, static fn (string $s): bool => str_starts_with($summary['Subject'], $s));
        if ($ours === []) {
            continue;
        }
        $message = json_decode((string) wp_remote_retrieve_body(wp_remote_get('http://mailpit:8025/api/v1/message/' . $summary['ID'])), true);
        $attachments = [];
        foreach ($message['Attachments'] ?? [] as $part) {
            $attachments[$part['FileName']] = (string) wp_remote_retrieve_body(
                wp_remote_get('http://mailpit:8025/api/v1/message/' . $summary['ID'] . '/part/' . $part['PartID'])
            );
        }
        $result[] = ['subject' => $summary['Subject'], 'html' => (string) ($message['HTML'] ?? ''), 'attachments' => $attachments];
    }
    return array_reverse($result);
};
$withPrefix = static fn (array $mails, string $prefix): array => array_values(array_filter(
    $mails,
    static fn (array $m): bool => str_starts_with($m['subject'], $prefix)
));

$repo = new EntitlementRepository();
$created = ['products' => [], 'orders' => [], 'users' => []];
$token = wp_generate_password(6, false, false);
$utc = new DateTimeZone('UTC');

/** Saves the product through the admin save handler with a new start (local time, minute precision). */
$setStart = static function (WC_Product $product, DateTimeImmutable $start): void {
    $webinar = Webinar::fromProduct($product);
    $_POST = [
        Webinar::META_TYPE => 'live',
        Webinar::META_STARTS_AT => $start->setTimezone(wp_timezone())->format('Y-m-d\TH:i'),
        Webinar::META_DURATION => '90',
        Webinar::META_ZOOM_ID => (string) $webinar?->zoomId,
        Webinar::META_ACCESS_DAYS => $webinar?->accessDays !== null ? (string) $webinar->accessDays : '',
    ];
    (new WebinarFields())->save($product);
    $_POST = [];
    $product->save();
};
$makeLive = static function (string $name, ?DateTimeImmutable $start) use (&$created, $setStart): WC_Product_Simple {
    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_regular_price('590');
    $product->set_status('publish');
    $product->update_meta_data(Webinar::META_TYPE, 'live');
    $product->update_meta_data(Webinar::META_ZOOM_ID, '99988877766');
    $product->update_meta_data(Webinar::META_ACCESS_DAYS, '30');
    $product->set_virtual(true);
    $product->set_sold_individually(true);
    $product->save();
    $created['products'][] = $product->get_id();
    if ($start !== null) {
        $setStart($product, $start);
    }
    return $product;
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
    $email = "schedule-{$role}-{$token}@example.test";
    $id = wp_insert_user([
        'user_login' => "nmd_smoke_{$role}_{$token}",
        'user_pass' => wp_generate_password(),
        'user_email' => $email,
        'first_name' => 'Petr',
        'last_name' => 'Dvořák',
        'role' => 'customer',
    ]);
    $created['users'][] = $id;
    return [$id, $email];
};
$scheduled = static fn (int $productId, string $kind) => as_next_scheduled_action(Reminders::HOOK_REMINDER, [$productId, $kind], ZoomRegistrar::GROUP);
$dtstart = static function (array $mail): string {
    $ics = (string) (array_values($mail['attachments'])[0] ?? '');
    return preg_match('/^DTSTART:(\S+)/m', $ics, $m) ? $m[1] : '';
};
$fmt = static fn (?DateTimeImmutable $d): string => $d?->format('Y-m-d H:i') ?? 'unlimited';

try {
    WP_CLI::log('Setup');
    $start = (new DateTimeImmutable('+7 days', wp_timezone()))->setTime(18, 0);
    $live = $makeLive("SMOKE Termíny {$token}", $start);
    [$buyer, $buyerEmail] = $makeUser('buyer');
    [$guest, $guestEmail] = $makeUser('guest');
    [$refunded, $refundedEmail] = $makeUser('refunded');
    $buy($buyer, $live);
    $refundOrder = $buy($refunded, $live);
    $manualUntil = (new DateTimeImmutable('+60 days', $utc))->setTime(12, 0);
    Plugin::access()->grant($guest, $live->get_id(), $manualUntil, 'manual', extend: false);
    $runQueue();

    WP_CLI::log('Reminders scheduled');
    $startTs = $start->getTimestamp();
    $check('24 h reminder at start − 24 h', $scheduled($live->get_id(), Reminders::KIND_24H) === $startTs - 86400);
    $check('1 h reminder at start − 1 h', $scheduled($live->get_id(), Reminders::KIND_1H) === $startTs - 3600);
    $live->set_stock_status('instock');
    $live->save(); // unrelated save (e.g. stock change) keeps the jobs
    $pending = as_get_scheduled_actions(['hook' => Reminders::HOOK_REMINDER, 'args' => [$live->get_id(), Reminders::KIND_24H], 'status' => ActionScheduler_Store::STATUS_PENDING], 'ids');
    $check('unrelated product save does not duplicate jobs', count($pending) === 1, (string) count($pending));
    $check('buyer got the access e-mail (not a "new date" one)', count($withPrefix($mails($buyerEmail), 'Váš přístup')) === 1 && $withPrefix($mails($buyerEmail), 'Nový termín') === []);

    WP_CLI::log('Start moved by two days');
    $newStart = $start->modify('+2 days');
    $setStart(wc_get_product($live->get_id()), $newStart);
    $check('start-change job queued', as_has_scheduled_action(StartChange::HOOK, null, ZoomRegistrar::GROUP));
    $runQueue();
    $newTs = $newStart->getTimestamp();
    $check('reminders follow the new start', $scheduled($live->get_id(), Reminders::KIND_24H) === $newTs - 86400 && $scheduled($live->get_id(), Reminders::KIND_1H) === $newTs - 3600);
    $e = $repo->find($buyer, $live->get_id());
    $check('purchased access: new start + 30 days', $e?->expiresAt == $newStart->setTimezone($utc)->modify('+30 days'), $fmt($e?->expiresAt));
    $e = $repo->find($guest, $live->get_id());
    $check('manual grant keeps its date', $e?->expiresAt == $manualUntil, $fmt($e?->expiresAt));
    $changed = $withPrefix($mails($buyerEmail), 'Nový termín');
    $expectedDtstart = $newStart->setTimezone($utc)->format('Ymd\THis\Z');
    $check('buyer got "Nový termín" e-mail', count($changed) === 1, (string) count($changed));
    $check('…with the new time in the calendar file', $changed !== [] && $dtstart($changed[0]) === $expectedDtstart, $changed !== [] ? $dtstart($changed[0]) : '');
    $check('…and the personal Zoom link', $changed !== [] && str_contains($changed[0]['html'], 'zoom.us/w/'));
    $check('manual-grant customer notified too', count($withPrefix($mails($guestEmail), 'Nový termín')) === 1);

    WP_CLI::log('Saving with the same start');
    $setStart(wc_get_product($live->get_id()), $newStart);
    $check('no start-change job', !as_has_scheduled_action(StartChange::HOOK, null, ZoomRegistrar::GROUP));
    $runQueue();
    $check('no second "Nový termín" e-mail', count($withPrefix($mails($buyerEmail), 'Nový termín')) === 1);

    WP_CLI::log('Refund before the reminder');
    $refundOrder->update_status('refunded');

    WP_CLI::log('24 h reminder');
    // Start just over 24 h ahead (minute precision of the form), then run the job as Action Scheduler would.
    $soon = new DateTimeImmutable('+1 day +3 minutes', wp_timezone());
    $soon = $soon->setTime((int) $soon->format('H'), (int) $soon->format('i'));
    $setStart(wc_get_product($live->get_id()), $soon);
    $runQueue(); // start change: "Nový termín" e-mails
    $soonTs = $soon->getTimestamp();
    $check('24 h reminder due in a few minutes', abs((int) $scheduled($live->get_id(), Reminders::KIND_24H) - (time() + 180)) < 120);
    do_action(Reminders::HOOK_REMINDER, $live->get_id(), Reminders::KIND_24H);
    $runQueue();
    $reminders = $withPrefix($mails($buyerEmail), 'Připomínka');
    $check('buyer got the reminder', count($reminders) === 1, (string) count($reminders));
    $check('…with the start date in the subject', $reminders !== [] && str_contains($reminders[0]['subject'], $soon->format('j. n. Y')) , $reminders[0]['subject'] ?? '');
    $check('…saying it starts tomorrow, with Zoom link and calendar', $reminders !== []
        && str_contains($reminders[0]['html'], 'začíná zítra')
        && str_contains($reminders[0]['html'], 'zoom.us/w/')
        && $dtstart($reminders[0]) === $soon->setTimezone($utc)->format('Ymd\THis\Z'));
    $check('manual-grant customer got it', count($withPrefix($mails($guestEmail), 'Připomínka')) === 1);
    $check('refunded customer did not', $withPrefix($mails($refundedEmail), 'Připomínka') === []);
    $sent = get_post_meta($live->get_id(), Reminders::META_SENT, true);
    $check('marked as sent for this start', is_array($sent) && ($sent[Reminders::KIND_24H] ?? null) === $soonTs);
    do_action(Reminders::HOOK_REMINDER, $live->get_id(), Reminders::KIND_24H);
    $runQueue();
    $check('running the job again sends nothing', count($withPrefix($mails($buyerEmail), 'Připomínka')) === 1);

    WP_CLI::log('Guards');
    do_action(Reminders::HOOK_REMINDER, $live->get_id(), Reminders::KIND_1H); // 23 hours early
    $runQueue();
    $check('1 h reminder run 23 h early sends nothing', count($withPrefix($mails($buyerEmail), 'Připomínka')) === 1);
    $check('…and stays scheduled for start − 1 h', $scheduled($live->get_id(), Reminders::KIND_1H) === $soonTs - 3600);
    $buyerAccess = $repo->find($buyer, $live->get_id());
    Reminders::enqueueSend($buyerAccess->id, Reminders::KIND_1H, $startTs); // stale start
    $runQueue();
    $check('send job for an outdated start sends nothing', count($withPrefix($mails($buyerEmail), 'Připomínka')) === 1);

    WP_CLI::log('1 h reminder');
    $inAnHour = (new DateTimeImmutable('+1 hour +3 minutes', wp_timezone()));
    $inAnHour = $inAnHour->setTime((int) $inAnHour->format('H'), (int) $inAnHour->format('i'));
    $setStart(wc_get_product($live->get_id()), $inAnHour);
    $runQueue();
    $check('24 h reminder dropped (too late for it)', $scheduled($live->get_id(), Reminders::KIND_24H) === false);
    $check('1 h reminder due in a few minutes', abs((int) $scheduled($live->get_id(), Reminders::KIND_1H) - (time() + 180)) < 120);
    do_action(Reminders::HOOK_REMINDER, $live->get_id(), Reminders::KIND_1H);
    $runQueue();
    $reminders = $withPrefix($mails($buyerEmail), 'Připomínka');
    $check('buyer got the 1 h reminder', count($reminders) === 2 && str_contains(end($reminders)['html'], 'začíná za hodinu'));

    WP_CLI::log('Start set after the purchase');
    $later = $makeLive("SMOKE Bez termínu {$token}", null);
    $buy($buyer, $later);
    $runQueue();
    $before = $repo->find($buyer, $later->get_id());
    $check('without a start: access counted from the purchase', $before !== null && abs($before->expiresAt->getTimestamp() - Clock::now()->modify('+30 days')->getTimestamp()) < 120, $fmt($before?->expiresAt));
    $check('no reminders without a start', $scheduled($later->get_id(), Reminders::KIND_24H) === false);
    $laterStart = (new DateTimeImmutable('+10 days', wp_timezone()))->setTime(17, 0);
    $countBefore = count($mails($buyerEmail));
    $setStart(wc_get_product($later->get_id()), $laterStart);
    $runQueue();
    $after = $repo->find($buyer, $later->get_id());
    $check('first start set: access from the start + 30 days', $after?->expiresAt == $laterStart->setTimezone($utc)->modify('+30 days'), $fmt($after?->expiresAt));
    $check('…no "Nový termín" e-mail (there was no date before)', count($mails($buyerEmail)) === $countBefore);
    $check('…reminders scheduled', $scheduled($later->get_id(), Reminders::KIND_24H) === $laterStart->getTimestamp() - 86400);
    $check('terms snapshot unchanged', wc_get_order_item_meta((int) $after->orderItemId, AccessTerms::ITEM_META_ACCESS_DAYS, true) === '30');

    WP_CLI::log('Product deleted');
    $laterId = $later->get_id();
    $later->delete(true);
    $created['products'] = array_values(array_diff($created['products'], [$laterId]));
    $check('reminders unscheduled with the product', $scheduled($laterId, Reminders::KIND_24H) === false && $scheduled($laterId, Reminders::KIND_1H) === false);
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
        $wpdb->delete($wpdb->prefix . 'nmd_entitlements', ['user_id' => $id]);
        wp_delete_user($id);
    }
    foreach ($created['products'] as $id) {
        wc_get_product($id)?->delete(true);
    }
    // Leftover jobs of deleted data would only no-op, but keep the queue clean.
    foreach (as_get_scheduled_actions(['group' => ZoomRegistrar::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 200], 'ids') as $id) {
        $action = ActionScheduler::store()->fetch_action((string) $id);
        $first = (int) ($action->get_args()[0] ?? 0);
        if (in_array($first, $created['products'], true) || $first === ($laterId ?? -1)) {
            ActionScheduler::store()->cancel_action((string) $id);
        }
    }
    $check('cleanup left no pending reminders of test products', array_filter(
        $created['products'],
        static fn (int $id): bool => $scheduled($id, Reminders::KIND_24H) !== false || $scheduled($id, Reminders::KIND_1H) !== false
    ) === []);
}

$failures === 0 ? WP_CLI::success('All checks passed.') : WP_CLI::error("{$failures} check(s) failed.");
