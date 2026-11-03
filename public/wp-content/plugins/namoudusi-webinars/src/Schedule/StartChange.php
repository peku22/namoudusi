<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Schedule;

use Namoudusi\Webinars\Access\AccessPeriod;
use Namoudusi\Webinars\Access\AccessTerms;
use Namoudusi\Webinars\Access\Clock;
use Namoudusi\Webinars\Access\Entitlement;
use Namoudusi\Webinars\Access\EntitlementRepository;
use Namoudusi\Webinars\Log;
use Namoudusi\Webinars\Product\Webinar;
use Namoudusi\Webinars\Zoom\ZoomRegistrar;

/**
 * When a live webinar's start time changes (product form fires `nmd_webinar_start_changed`):
 * - purchased access counted from the event start gets a new expiry (same number of days after the new start);
 * - buyers get a "time changed" e-mail with an updated calendar file, if the webinar had a time before
 *   and the new one is still ahead.
 * Runs as a background job after the product is saved.
 */
final class StartChange
{
    public const HOOK = 'nmd_webinar_start_changed_job';

    public function __construct(private readonly EntitlementRepository $entitlements)
    {
    }

    public function register(): void
    {
        add_action('nmd_webinar_start_changed', [$this, 'schedule'], 10, 2);
        add_action(self::HOOK, [$this, 'handle'], 10, 2);
    }

    /**
     * @param int $previousStart previous start timestamp, 0 if there was none
     */
    public function schedule(int $productId, int $previousStart = 0): void
    {
        $args = [$productId, $previousStart];
        if (!as_has_scheduled_action(self::HOOK, $args, ZoomRegistrar::GROUP)) {
            as_enqueue_async_action(self::HOOK, $args, ZoomRegistrar::GROUP);
        }
    }

    public function handle(int $productId, int $previousStart = 0): void
    {
        $webinar = Webinar::fromProductId($productId);
        if ($webinar === null || !$webinar->isLive() || $webinar->startsAt === null) {
            return;
        }
        $start = $webinar->startsAt->getTimestamp();
        $notify = $previousStart > 0 && $previousStart !== $start && $start > time();
        $updated = 0;

        // All non-revoked access, also already expired: a later start can make it valid again.
        foreach ($this->entitlements->all(null, $productId) as $entitlement) {
            if ($entitlement->revokedAt !== null) {
                continue;
            }
            if ($this->recomputeExpiry($entitlement, $webinar)) {
                $updated++;
                $entitlement = $this->entitlements->findById($entitlement->id) ?? $entitlement;
            }
            if ($notify && $entitlement->isActive(Clock::now())) {
                Reminders::enqueueSend($entitlement->id, Reminders::KIND_CHANGED, $start);
            }
        }
        Log::info(sprintf(
            'Start of webinar #%d changed to %s: %d access expiries updated, buyers %s.',
            $productId,
            $webinar->startsAt->format('Y-m-d H:i') . ' UTC',
            $updated,
            $notify ? 'notified' : 'not notified'
        ));
    }

    /**
     * Purchased access only – manual grants keep the date the admin chose.
     */
    private function recomputeExpiry(Entitlement $entitlement, Webinar $webinar): bool
    {
        if ($entitlement->source !== Entitlement::SOURCE_ORDER || $entitlement->orderItemId === null) {
            return false;
        }
        $terms = wc_get_order_item_meta($entitlement->orderItemId, AccessTerms::ITEM_META_ACCESS_DAYS, true);
        if (!is_string($terms) || $terms === '') {
            return false;
        }
        $order = $entitlement->orderId !== null ? wc_get_order($entitlement->orderId) : null;
        $paidAt = $order instanceof \WC_Order ? ($order->get_date_paid() ?? $order->get_date_created()) : null;
        $purchasedAt = $paidAt !== null ? Clock::fromTimestamp($paidAt->getTimestamp()) : $entitlement->grantedAt;

        $expiresAt = AccessPeriod::expiresAt(AccessTerms::decode($terms), $purchasedAt, $webinar->startsAt);
        if ($expiresAt == $entitlement->expiresAt) {
            return false;
        }
        $this->entitlements->update($entitlement->id, ['expires_at' => $expiresAt]);
        return true;
    }
}
