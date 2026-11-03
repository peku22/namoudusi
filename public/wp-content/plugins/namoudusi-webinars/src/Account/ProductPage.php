<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Account;

use Namoudusi\Webinars\Access\AccessService;
use Namoudusi\Webinars\Product\Webinar;

/**
 * Customers who already have access see "Go to webinar" instead of buying again.
 */
final class ProductPage
{
    public function __construct(private readonly AccessService $access)
    {
    }

    public function register(): void
    {
        add_filter('woocommerce_is_purchasable', [$this, 'isPurchasable'], 10, 2);
        add_action('woocommerce_simple_add_to_cart', [$this, 'renderAccessButton'], 5);
        add_action('woocommerce_thankyou', [$this, 'renderThankYouLink'], 5);
    }

    public function isPurchasable(mixed $purchasable, mixed $product): bool
    {
        if ($product instanceof \WC_Product && $this->currentUserHasAccess($product)) {
            return false;
        }
        return (bool) $purchasable;
    }

    public function renderAccessButton(): void
    {
        global $product;
        if (!$product instanceof \WC_Product || !$this->currentUserHasAccess($product)) {
            return;
        }
        printf(
            '<p class="nmd-access-notice">%s</p><p><a class="button alt wp-element-button" href="%s">%s</a></p>',
            esc_html__('You already have access to this webinar.', 'namoudusi-webinars'),
            esc_url(WebinarsEndpoint::url($product->get_id())),
            esc_html__('Go to webinar', 'namoudusi-webinars')
        );
    }

    public function renderThankYouLink(mixed $orderId): void
    {
        $order = wc_get_order((int) $orderId);
        if (!$order instanceof \WC_Order) {
            return;
        }
        foreach ($order->get_items() as $item) {
            if ($item instanceof \WC_Order_Item_Product && Webinar::fromProductId($item->get_product_id()) !== null) {
                printf(
                    '<p class="nmd-thankyou"><a class="button wp-element-button" href="%s">%s</a></p>',
                    esc_url(WebinarsEndpoint::url()),
                    esc_html__('Go to My webinars', 'namoudusi-webinars')
                );
                return;
            }
        }
    }

    private function currentUserHasAccess(\WC_Product $product): bool
    {
        $userId = get_current_user_id();
        if ($userId === 0 || is_admin()) {
            return false;
        }
        return Webinar::fromProduct($product) !== null && $this->access->hasAccess($userId, $product->get_id());
    }
}
