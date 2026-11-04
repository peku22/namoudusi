<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Admin;

use Namoudusi\Webinars\Access\AccessFormatter;
use Namoudusi\Webinars\Access\AccessService;
use Namoudusi\Webinars\Access\Entitlement;
use Namoudusi\Webinars\Access\EntitlementRepository;
use Namoudusi\Webinars\Checkout\DigitalConsent;
use Namoudusi\Webinars\Product\Webinar;
use Namoudusi\Webinars\Product\WebinarProducts;

/**
 * Webinar access in the admin: overview on the order screen, list + manual grant/revoke on the user profile.
 */
final class AccessAdmin
{
    private const NONCE = 'nmd_access_admin';

    public function __construct(
        private readonly AccessService $access,
        private readonly EntitlementRepository $entitlements,
    ) {
    }

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addOrderMetaBox']);
        add_action('show_user_profile', [$this, 'renderUserSection']);
        add_action('edit_user_profile', [$this, 'renderUserSection']);
        add_action('personal_options_update', [$this, 'saveUserSection']);
        add_action('edit_user_profile_update', [$this, 'saveUserSection']);
    }

    public function addOrderMetaBox(): void
    {
        add_meta_box(
            'nmd-order-access',
            __('Webinar access', 'namoudusi-webinars'),
            [$this, 'renderOrderMetaBox'],
            wc_get_page_screen_id('shop-order'),
            'side'
        );
    }

    public function renderOrderMetaBox(mixed $postOrOrder): void
    {
        $order = $postOrOrder instanceof \WP_Post ? wc_get_order($postOrOrder->ID) : $postOrOrder;
        if (!$order instanceof \WC_Order) {
            return;
        }
        $this->renderConsent($order);
        $entitlements = $this->entitlements->forOrder($order->get_id());
        if ($entitlements === []) {
            echo '<p>' . esc_html__('No webinar access granted by this order.', 'namoudusi-webinars') . '</p>';
            return;
        }
        echo '<ul>';
        foreach ($entitlements as $entitlement) {
            $webinar = Webinar::fromProductId($entitlement->productId);
            $zoom = '';
            if ($webinar !== null && $webinar->isLive() && $entitlement->revokedAt === null) {
                $zoom = '<br>' . esc_html(
                    (string) $entitlement->zoomJoinUrl !== ''
                        ? __('Zoom: registered', 'namoudusi-webinars')
                        : __('Zoom: waiting for registration', 'namoudusi-webinars')
                );
            }
            printf(
                '<li><strong>%s</strong><br>%s, %s%s</li>',
                esc_html($this->productName($entitlement->productId)),
                esc_html(AccessFormatter::status($entitlement)),
                esc_html(AccessFormatter::expiry($entitlement->expiresAt)),
                $zoom // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
            );
        }
        echo '</ul>';
    }

    /**
     * Consent to immediate delivery of digital content (block checkout field), for orders with webinars.
     */
    private function renderConsent(\WC_Order $order): void
    {
        $consent = DigitalConsent::forOrder($order);
        if ($consent !== null) {
            printf(
                '<p class="nmd-consent"><strong>%s</strong><br>%s<br><small>%s</small></p>',
                esc_html__('Consent to immediate delivery:', 'namoudusi-webinars'),
                /* translators: %s: date and time */
                esc_html(sprintf(__('given %s', 'namoudusi-webinars'), AccessFormatter::date($consent['givenAt']))),
                esc_html($consent['text'])
            );
            return;
        }
        foreach ($order->get_items() as $item) {
            if ($item instanceof \WC_Order_Item_Product && Webinar::fromProductId($item->get_product_id()) !== null) {
                printf(
                    '<p class="nmd-consent"><strong>%s</strong><br>%s</p>',
                    esc_html__('Consent to immediate delivery:', 'namoudusi-webinars'),
                    esc_html__('not given (e.g. order created in the admin) – the customer keeps the right of withdrawal.', 'namoudusi-webinars')
                );
                return;
            }
        }
    }

    public function renderUserSection(\WP_User $user): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $entitlements = $this->entitlements->forUser($user->ID);
        $products = WebinarProducts::options();
        ?>
        <h2><?php esc_html_e('Webinar access', 'namoudusi-webinars'); ?></h2>
        <?php wp_nonce_field(self::NONCE, self::NONCE); ?>
        <table class="widefat striped" style="max-width:60em">
            <thead>
            <tr>
                <th><?php esc_html_e('Webinar', 'namoudusi-webinars'); ?></th>
                <th><?php esc_html_e('Source', 'namoudusi-webinars'); ?></th>
                <th><?php esc_html_e('Granted', 'namoudusi-webinars'); ?></th>
                <th><?php esc_html_e('Expires', 'namoudusi-webinars'); ?></th>
                <th><?php esc_html_e('Status', 'namoudusi-webinars'); ?></th>
                <th><?php esc_html_e('Revoke', 'namoudusi-webinars'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($entitlements === []) : ?>
                <tr><td colspan="6"><?php esc_html_e('No webinar access yet.', 'namoudusi-webinars'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($entitlements as $entitlement) : ?>
                <tr>
                    <td><?php echo esc_html($this->productName($entitlement->productId)); ?></td>
                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in sourceHtml(). ?>
                    <td><?php echo $this->sourceHtml($entitlement); ?></td>
                    <td><?php echo esc_html(AccessFormatter::date($entitlement->grantedAt)); ?></td>
                    <td><?php echo esc_html(AccessFormatter::expiry($entitlement->expiresAt)); ?></td>
                    <td><?php echo esc_html(AccessFormatter::status($entitlement)); ?></td>
                    <td>
                        <?php if ($entitlement->revokedAt === null) : ?>
                            <input type="checkbox" name="nmd_revoke[]"
                                   value="<?php echo esc_attr((string) $entitlement->id); ?>">
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h3><?php esc_html_e('Grant access', 'namoudusi-webinars'); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="nmd_grant_product"><?php esc_html_e('Webinar', 'namoudusi-webinars'); ?></label></th>
                <td>
                    <select name="nmd_grant_product" id="nmd_grant_product">
                        <option value=""><?php esc_html_e('— Select —', 'namoudusi-webinars'); ?></option>
                        <?php foreach ($products as $id => $name) : ?>
                            <option value="<?php echo esc_attr((string) $id); ?>">
                                <?php echo esc_html($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="nmd_grant_until"><?php esc_html_e('Access until', 'namoudusi-webinars'); ?></label></th>
                <td>
                    <input type="date" name="nmd_grant_until" id="nmd_grant_until">
                    <p class="description">
                        <?php esc_html_e(
                            'Leave empty for no time limit. Replaces any existing expiry of this webinar.',
                            'namoudusi-webinars'
                        ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function saveUserSection(int $userId): void
    {
        if (!current_user_can('manage_woocommerce') || !isset($_POST[self::NONCE])) {
            return;
        }
        check_admin_referer(self::NONCE, self::NONCE);

        $revokeIds = isset($_POST['nmd_revoke']) ? array_map('absint', (array) wp_unslash($_POST['nmd_revoke'])) : [];
        foreach ($revokeIds as $id) {
            $entitlement = $this->entitlements->findById($id);
            if ($entitlement !== null && $entitlement->userId === $userId) {
                $this->access->revoke($entitlement);
            }
        }

        $productId = isset($_POST['nmd_grant_product']) ? absint($_POST['nmd_grant_product']) : 0;
        if ($productId === 0 || !array_key_exists($productId, WebinarProducts::options())) {
            return;
        }
        $until = isset($_POST['nmd_grant_until']) ? sanitize_text_field(wp_unslash($_POST['nmd_grant_until'])) : '';
        $this->access->grant(
            $userId,
            $productId,
            self::endOfDay($until),
            Entitlement::SOURCE_MANUAL,
            extend: false
        );
    }

    /**
     * "Access until 2026-12-31" means the end of that day in the site timezone.
     */
    public static function endOfDay(string $date): ?\DateTimeImmutable
    {
        $local = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, wp_timezone());
        return $local !== false ? $local->setTime(23, 59, 59)->setTimezone(new \DateTimeZone('UTC')) : null;
    }

    private function sourceHtml(Entitlement $entitlement): string
    {
        $label = esc_html(AccessFormatter::source($entitlement));
        if ($entitlement->orderId === null) {
            return $label;
        }
        $order = wc_get_order($entitlement->orderId);
        if (!$order instanceof \WC_Order) {
            return $label . ' #' . esc_html((string) $entitlement->orderId);
        }
        return sprintf(
            '%s <a href="%s">#%s</a>',
            $label,
            esc_url($order->get_edit_order_url()),
            esc_html($order->get_order_number())
        );
    }

    private function productName(int $productId): string
    {
        $product = wc_get_product($productId);
        return $product instanceof \WC_Product ? $product->get_name() : '#' . $productId;
    }
}
