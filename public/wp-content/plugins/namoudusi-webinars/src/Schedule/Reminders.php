<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Schedule;

use Namoudusi\Webinars\Access\Clock;
use Namoudusi\Webinars\Access\EntitlementRepository;
use Namoudusi\Webinars\Email\Emails;
use Namoudusi\Webinars\Log;
use Namoudusi\Webinars\Product\Webinar;
use Namoudusi\Webinars\Zoom\ZoomRegistrar;

/**
 * Reminder e-mails before a live webinar (24 h and 1 h), one Action Scheduler job per product and reminder.
 *
 * The jobs follow the product's start time: every product save compares the scheduled time with the start
 * and reschedules if needed. A reminder job fans out into one send job per buyer, so a slow mail server
 * never blocks the queue, and a product-level marker makes sure a reminder goes out only once per start time.
 */
final class Reminders
{
    public const HOOK_REMINDER = 'nmd_webinar_reminder';
    public const HOOK_SEND = 'nmd_webinar_notify';

    public const KIND_24H = '24h';
    public const KIND_1H = '1h';
    /** Not a reminder: sent by StartChange when the start time moves. */
    public const KIND_CHANGED = 'changed';

    /** Product meta: kind => start timestamp the reminder was sent for. */
    public const META_SENT = '_nmd_reminders_sent';

    /** Seconds before the start. */
    private const OFFSETS = [self::KIND_24H => 86400, self::KIND_1H => 3600];

    /** A job running this late after its planned time (cron outage) is skipped instead of sent. */
    private const MAX_DELAY = 1800;

    public function __construct(private readonly EntitlementRepository $entitlements)
    {
    }

    public function register(): void
    {
        add_action('woocommerce_after_product_object_save', [$this, 'onProductSaved']);
        add_action('before_delete_post', [$this, 'onPostDeleted']);
        add_action(self::HOOK_REMINDER, [$this, 'handleReminder'], 10, 2);
        add_action(self::HOOK_SEND, [$this, 'handleSend'], 10, 3);
    }

    /**
     * @return array<string, int> kind => seconds before the start
     */
    public static function offsets(): array
    {
        return self::OFFSETS;
    }

    public function onProductSaved(mixed $product): void
    {
        if ($product instanceof \WC_Product) {
            $this->sync($product->get_id());
        }
    }

    public function onPostDeleted(int $postId): void
    {
        if (get_post_type($postId) === 'product') {
            $this->unscheduleAll($postId);
        }
    }

    /**
     * Makes the scheduled reminder jobs match the product's current start time (idempotent).
     */
    public function sync(int $productId): void
    {
        $webinar = Webinar::fromProductId($productId);
        $start = $webinar !== null && $webinar->isLive() ? $webinar->startsAt?->getTimestamp() : null;

        foreach (self::OFFSETS as $kind => $offset) {
            $args = [$productId, $kind];
            $due = $start !== null ? $start - $offset : null;
            $scheduled = as_next_scheduled_action(self::HOOK_REMINDER, $args, ZoomRegistrar::GROUP);
            if ($scheduled === true) {
                continue; // running right now
            }
            if ($due === null || $due <= time()) {
                // No start, or too late for this reminder (e.g. a webinar created 10 hours before it starts).
                if ($scheduled !== false) {
                    as_unschedule_all_actions(self::HOOK_REMINDER, $args, ZoomRegistrar::GROUP);
                }
                continue;
            }
            if ($scheduled !== $due) {
                as_unschedule_all_actions(self::HOOK_REMINDER, $args, ZoomRegistrar::GROUP);
                as_schedule_single_action($due, self::HOOK_REMINDER, $args, ZoomRegistrar::GROUP);
            }
        }
    }

    public function handleReminder(int $productId, string $kind): void
    {
        $webinar = Webinar::fromProductId($productId);
        $offset = self::OFFSETS[$kind] ?? null;
        if ($webinar === null || !$webinar->isLive() || $webinar->startsAt === null || $offset === null) {
            return;
        }
        $start = $webinar->startsAt->getTimestamp();
        $untilStart = $start - time();
        $lateBy = $offset - $untilStart;
        if ($untilStart <= 0 || $lateBy > self::MAX_DELAY || $lateBy < -self::MAX_DELAY) {
            // Started already, cron was down too long, or the start moved without a product save.
            Log::info(sprintf('Reminder %s for product #%d skipped (starts in %d s).', $kind, $productId, $untilStart));
            $this->sync($productId);
            return;
        }

        $sent = get_post_meta($productId, self::META_SENT, true);
        $sent = is_array($sent) ? $sent : [];
        if (($sent[$kind] ?? null) === $start) {
            return;
        }

        if ($webinar->zoomId === '') {
            Log::warning(sprintf(
                'Live webinar #%d "%s" starts in %d minutes and has no Zoom ID.',
                $productId,
                $webinar->name,
                intdiv($untilStart, 60)
            ));
        }
        foreach ($this->entitlements->activeForProduct($productId) as $entitlement) {
            self::enqueueSend($entitlement->id, $kind, $start);
        }
        $sent[$kind] = $start;
        update_post_meta($productId, self::META_SENT, $sent);
    }

    /**
     * Sends one buyer's e-mail, unless the access ended or the start moved meanwhile.
     */
    public function handleSend(int $entitlementId, string $kind, int $start): void
    {
        $entitlement = $this->entitlements->findById($entitlementId);
        $webinar = $entitlement !== null ? Webinar::fromProductId($entitlement->productId) : null;
        if (
            $entitlement === null || $webinar === null || !$entitlement->isActive(Clock::now())
            || $webinar->startsAt?->getTimestamp() !== $start || $start <= time()
        ) {
            return;
        }
        Emails::sendNotification($kind, $entitlement);
    }

    public static function enqueueSend(int $entitlementId, string $kind, int $start): void
    {
        $args = [$entitlementId, $kind, $start];
        if (!as_has_scheduled_action(self::HOOK_SEND, $args, ZoomRegistrar::GROUP)) {
            as_enqueue_async_action(self::HOOK_SEND, $args, ZoomRegistrar::GROUP);
        }
    }

    private function unscheduleAll(int $productId): void
    {
        foreach (array_keys(self::OFFSETS) as $kind) {
            as_unschedule_all_actions(self::HOOK_REMINDER, [$productId, $kind], ZoomRegistrar::GROUP);
        }
    }
}
