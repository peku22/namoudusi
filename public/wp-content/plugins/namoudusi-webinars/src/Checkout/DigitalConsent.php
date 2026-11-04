<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Checkout;

use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use Namoudusi\Webinars\Access\Clock;
use Namoudusi\Webinars\Product\Webinar;

/**
 * Checkout consent to immediate delivery of digital content and the loss of the right of withdrawal
 * (§ 1837 písm. l) občanského zákoníku), as an Additional Checkout Field of the block checkout.
 *
 * Shown and required only when the cart contains a webinar: the Store API cart carries
 * `extensions.namoudusi.has_webinar`, the field rules read it (on the client and again on the server).
 * If the flag were missing, the field stays visible and required (fail closed).
 *
 * WooCommerce stores the value itself (order meta `_wc_other/namoudusi/digital-content-consent`);
 * we add the time and the exact wording the customer agreed to.
 */
final class DigitalConsent
{
    public const FIELD_ID = 'namoudusi/digital-content-consent';
    /** Where WooCommerce stores the field value (group "other" = order location). */
    public const META_VALUE = '_wc_other/' . self::FIELD_ID;
    public const META_AT = '_nmd_digital_consent_at';
    public const META_TEXT = '_nmd_digital_consent_text';
    private const EXTENSION = 'namoudusi';

    public function register(): void
    {
        add_action('woocommerce_init', [$this, 'registerField']);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'recordConsent']);
    }

    public function registerField(): void
    {
        if (
            !function_exists('woocommerce_register_additional_checkout_field')
            || !function_exists('woocommerce_store_api_register_endpoint_data')
        ) {
            return;
        }
        woocommerce_store_api_register_endpoint_data([
            'endpoint' => CartSchema::IDENTIFIER,
            'namespace' => self::EXTENSION,
            'data_callback' => static fn (): array => ['has_webinar' => self::cartHasWebinar()],
            'schema_callback' => static fn (): array => [
                'has_webinar' => [
                    'description' => 'Whether the cart contains a webinar.',
                    'type' => 'boolean',
                    'readonly' => true,
                ],
            ],
            'schema_type' => ARRAY_A,
        ]);

        woocommerce_register_additional_checkout_field([
            'id' => self::FIELD_ID,
            'label' => self::label(),
            'location' => 'order',
            'type' => 'checkbox',
            'required' => self::rule(true),
            'hidden' => self::rule(false),
            'error_message' => __('Please confirm the consent to immediate delivery of the digital content.', 'namoudusi-webinars'),
        ]);
    }

    /** The consent wording shown at checkout (and stored on the order). */
    public static function label(): string
    {
        return __(
            'I agree that the digital content will be made available to me immediately after payment, and I acknowledge that I thereby lose the right to withdraw from the contract.', // phpcs:ignore Generic.Files.LineLength.TooLong -- one translatable literal.
            'namoudusi-webinars'
        );
    }

    public function recordConsent(mixed $order): void
    {
        if (!$order instanceof \WC_Order) {
            return;
        }
        $given = (bool) $order->get_meta(self::META_VALUE);
        if ($given && $order->get_meta(self::META_AT) === '') {
            $order->update_meta_data(self::META_AT, Clock::now()->format('Y-m-d H:i:s'));
            $order->update_meta_data(self::META_TEXT, self::label());
            $order->save();
            $order->add_order_note(sprintf(
                /* translators: %s: consent wording */
                __('Customer consented to immediate delivery of digital content: "%s"', 'namoudusi-webinars'),
                self::label()
            ));
        } elseif (!$given && $order->get_meta(self::META_AT) !== '') {
            // Retried checkout of the same draft order without the box ticked.
            $order->delete_meta_data(self::META_AT);
            $order->delete_meta_data(self::META_TEXT);
            $order->save();
        }
    }

    /**
     * @return array{givenAt: \DateTimeImmutable, text: string}|null
     */
    public static function forOrder(\WC_Order $order): ?array
    {
        $at = (string) $order->get_meta(self::META_AT);
        if ($at === '') {
            return null;
        }
        return [
            'givenAt' => new \DateTimeImmutable($at, new \DateTimeZone('UTC')),
            'text' => (string) $order->get_meta(self::META_TEXT),
        ];
    }

    public static function cartHasWebinar(): bool
    {
        $cart = function_exists('WC') ? WC()->cart : null;
        if ($cart === null) {
            return false;
        }
        foreach ($cart->get_cart() as $item) {
            if (Webinar::fromProductId((int) ($item['product_id'] ?? 0)) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * JSON-schema rule "the cart contains a webinar" (true) / "the cart contains no webinar" (false).
     * A missing flag only satisfies neither: then the field is visible and required.
     *
     * @return array<string, mixed>
     */
    private static function rule(bool $hasWebinar): array
    {
        return [
            'cart' => [
                'required' => ['extensions'],
                'properties' => [
                    'extensions' => [
                        'required' => [self::EXTENSION],
                        'properties' => [
                            self::EXTENSION => [
                                'required' => ['has_webinar'],
                                'properties' => ['has_webinar' => ['const' => $hasWebinar]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
