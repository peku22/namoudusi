<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Access;

use Namoudusi\Webinars\Product\Webinar;

/**
 * Connects WooCommerce orders to webinar access:
 * completed → grant, refunded/cancelled (whole order or fully refunded line) → revoke.
 */
final class OrderListener
{
    public function __construct(
        private readonly AccessService $access,
        private readonly EntitlementRepository $entitlements,
    ) {
    }

    public function register(): void
    {
        add_filter('woocommerce_order_item_needs_processing', [$this, 'itemNeedsProcessing'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'snapshotTerms'], 10, 3);
        add_action('woocommerce_order_status_completed', [$this, 'grantForOrder']);
        add_action('woocommerce_order_status_refunded', [$this, 'revokeForOrder']);
        add_action('woocommerce_order_status_cancelled', [$this, 'revokeForOrder']);
        add_action('woocommerce_order_refunded', [$this, 'revokeRefundedItems']);
    }

    /**
     * Webinars need no manual fulfilment, so paid webinar-only orders go straight to "completed".
     */
    public function itemNeedsProcessing(mixed $needsProcessing, mixed $product): bool
    {
        if ($product instanceof \WC_Product && Webinar::fromProduct($product) !== null) {
            return false;
        }
        return (bool) $needsProcessing;
    }

    public function snapshotTerms(\WC_Order_Item_Product $item, mixed $cartItemKey = null, mixed $values = null): void
    {
        $product = $item->get_product();
        $webinar = Webinar::fromProduct($product instanceof \WC_Product ? $product : null);
        if ($webinar !== null) {
            $item->add_meta_data(AccessTerms::ITEM_META_ACCESS_DAYS, AccessTerms::encode($webinar->accessDays), true);
        }
    }

    public function grantForOrder(int|string $orderId): void
    {
        $order = wc_get_order((int) $orderId);
        if (!$order instanceof \WC_Order) {
            return;
        }
        $userId = $order->get_customer_id();
        $paidAt = $order->get_date_paid() ?? $order->get_date_created();
        $purchasedAt = $paidAt !== null ? Clock::fromTimestamp($paidAt->getTimestamp()) : Clock::now();

        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $webinar = Webinar::fromProductId($item->get_product_id());
            if ($webinar === null) {
                continue;
            }
            if ($userId === 0) {
                $order->add_order_note(sprintf(
                    /* translators: %s: product name */
                    __('Webinar "%s": no customer account on the order, access not granted.', 'namoudusi-webinars'),
                    $item->get_name()
                ));
                continue;
            }

            $expiresAt = AccessPeriod::expiresAt(
                $this->accessDays($item, $webinar),
                $purchasedAt,
                $webinar->isLive() ? $webinar->startsAt : null
            );
            $entitlement = $this->access->grant(
                $userId,
                $webinar->productId,
                $expiresAt,
                Entitlement::SOURCE_ORDER,
                $order->get_id(),
                $item->get_id()
            );
            $order->add_order_note(sprintf(
                /* translators: 1: product name, 2: expiry date or "no time limit" */
                __('Access to webinar "%1$s" granted: %2$s.', 'namoudusi-webinars'),
                $item->get_name(),
                AccessFormatter::expiry($entitlement->expiresAt)
            ));
        }
    }

    public function revokeForOrder(int|string $orderId): void
    {
        $order = wc_get_order((int) $orderId);
        foreach ($this->entitlements->forOrder((int) $orderId) as $entitlement) {
            $this->revokeWithNote($entitlement, $order);
        }
    }

    /**
     * Partial refunds: revoke access for lines that are now fully refunded.
     */
    public function revokeRefundedItems(int|string $orderId): void
    {
        $order = wc_get_order((int) $orderId);
        if (!$order instanceof \WC_Order) {
            return;
        }
        $byItem = [];
        foreach ($this->entitlements->forOrder($order->get_id()) as $entitlement) {
            $byItem[$entitlement->orderItemId] = $entitlement;
        }
        foreach ($order->get_items() as $item) {
            $entitlement = $byItem[$item->get_id()] ?? null;
            if ($entitlement === null || !$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            if (abs($order->get_qty_refunded_for_item($item->get_id())) >= $item->get_quantity()) {
                $this->revokeWithNote($entitlement, $order);
            }
        }
    }

    /**
     * Access terms sold with this order line; falls back to the product (orders created in the admin).
     */
    private function accessDays(\WC_Order_Item_Product $item, Webinar $webinar): ?int
    {
        if ($item->meta_exists(AccessTerms::ITEM_META_ACCESS_DAYS)) {
            return AccessTerms::decode((string) $item->get_meta(AccessTerms::ITEM_META_ACCESS_DAYS));
        }
        $item->add_meta_data(AccessTerms::ITEM_META_ACCESS_DAYS, AccessTerms::encode($webinar->accessDays), true);
        $item->save_meta_data();
        return $webinar->accessDays;
    }

    private function revokeWithNote(Entitlement $entitlement, mixed $order): void
    {
        if ($entitlement->revokedAt !== null) {
            return;
        }
        $this->access->revoke($entitlement);
        if ($order instanceof \WC_Order) {
            $product = wc_get_product($entitlement->productId);
            $order->add_order_note(sprintf(
                /* translators: %s: product name */
                __('Access to webinar "%s" revoked.', 'namoudusi-webinars'),
                $product instanceof \WC_Product ? $product->get_name() : '#' . $entitlement->productId
            ));
        }
    }
}
