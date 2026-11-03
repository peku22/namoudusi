<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Product;

/**
 * "Webinar" tab in the WooCommerce product data box.
 */
final class WebinarFields
{
    private const LOCAL_INPUT_FORMAT = 'Y-m-d\TH:i';

    public function register(): void
    {
        add_filter('woocommerce_product_data_tabs', [$this, 'addTab']);
        add_action('woocommerce_product_data_panels', [$this, 'renderPanel']);
        add_action('woocommerce_admin_process_product_object', [$this, 'save']);
    }

    /**
     * @param array<string, array<string, mixed>> $tabs
     * @return array<string, array<string, mixed>>
     */
    public function addTab(array $tabs): array
    {
        $tabs['nmd_webinar'] = [
            'label' => __('Webinar', 'namoudusi-webinars'),
            'target' => 'nmd_webinar_data',
            'class' => ['show_if_simple'],
            'priority' => 15,
        ];
        return $tabs;
    }

    public function renderPanel(): void
    {
        global $product_object;
        $product = $product_object instanceof \WC_Product ? $product_object : null;
        $meta = static fn (string $key): string => $product !== null ? (string) $product->get_meta($key) : '';

        echo '<div id="nmd_webinar_data" class="panel woocommerce_options_panel hidden">';

        echo '<div class="options_group">';
        woocommerce_wp_select([
            'id' => Webinar::META_TYPE,
            'label' => __('Webinar type', 'namoudusi-webinars'),
            'value' => $meta(Webinar::META_TYPE),
            'options' => [
                '' => __('— Not a webinar —', 'namoudusi-webinars'),
                WebinarType::Recorded->value => __('Recorded', 'namoudusi-webinars'),
                WebinarType::Live->value => __('Live (Zoom)', 'namoudusi-webinars'),
            ],
            'desc_tip' => true,
            'description' => __('Webinar products are always virtual and sold one per customer.', 'namoudusi-webinars'),
        ]);
        woocommerce_wp_text_input([
            'id' => Webinar::META_ACCESS_DAYS,
            'label' => __('Access (days)', 'namoudusi-webinars'),
            'value' => $meta(Webinar::META_ACCESS_DAYS),
            'type' => 'number',
            'placeholder' => __('No time limit', 'namoudusi-webinars'),
            'custom_attributes' => ['min' => '1', 'step' => '1'],
            'desc_tip' => true,
            'description' => __(
                'Empty = no time limit. Live: counted from the event start; recorded: from the purchase.',
                'namoudusi-webinars'
            ),
        ]);
        woocommerce_wp_text_input([
            'id' => Webinar::META_BUNNY_VIDEO_ID,
            'label' => __('Bunny video ID', 'namoudusi-webinars'),
            'value' => $meta(Webinar::META_BUNNY_VIDEO_ID),
            'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
            'desc_tip' => true,
            'description' => __(
                'Required for recorded webinars. For live webinars, add the recording here after the event.',
                'namoudusi-webinars'
            ),
        ]);
        echo '</div>';

        echo '<div class="options_group nmd-live-only">';
        woocommerce_wp_text_input([
            'id' => Webinar::META_STARTS_AT,
            'label' => __('Start', 'namoudusi-webinars'),
            'value' => $this->toLocalInput($meta(Webinar::META_STARTS_AT)),
            'type' => 'datetime-local',
            'desc_tip' => true,
            'description' => sprintf(
                /* translators: %s: timezone name */
                __('Time in %s.', 'namoudusi-webinars'),
                wp_timezone_string()
            ),
        ]);
        woocommerce_wp_text_input([
            'id' => Webinar::META_DURATION,
            'label' => __('Duration (minutes)', 'namoudusi-webinars'),
            'value' => $meta(Webinar::META_DURATION),
            'type' => 'number',
            'custom_attributes' => ['min' => '1', 'step' => '1'],
        ]);
        woocommerce_wp_text_input([
            'id' => Webinar::META_ZOOM_ID,
            'label' => __('Zoom ID', 'namoudusi-webinars'),
            'value' => $meta(Webinar::META_ZOOM_ID),
            'placeholder' => '123 4567 8901',
            'desc_tip' => true,
            'description' => __(
                'Can be added later; buyers are then registered automatically. Registration must be enabled in Zoom.',
                'namoudusi-webinars'
            ),
        ]);
        woocommerce_wp_select([
            'id' => Webinar::META_ZOOM_KIND,
            'label' => __('Zoom type', 'namoudusi-webinars'),
            'value' => $meta(Webinar::META_ZOOM_KIND) ?: ZoomKind::Meeting->value,
            'options' => [
                ZoomKind::Meeting->value => __('Meeting', 'namoudusi-webinars'),
                ZoomKind::Webinar->value => __('Webinar (Zoom Webinars add-on)', 'namoudusi-webinars'),
            ],
        ]);
        echo '</div>';

        echo '</div>';
        ?>
        <script>
            jQuery(function ($) {
                var $type = $('#<?php echo esc_js(Webinar::META_TYPE); ?>');
                var toggle = function () { $('#nmd_webinar_data .nmd-live-only').toggle($type.val() === 'live'); };
                $type.on('change', toggle);
                toggle();
            });
        </script>
        <?php
    }

    public function save(\WC_Product $product): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its product nonce before this hook.
        $input = static fn (string $key): string => isset($_POST[$key])
            ? trim(sanitize_text_field(wp_unslash($_POST[$key])))
            : '';
        // phpcs:enable

        $type = WebinarType::tryFrom($input(Webinar::META_TYPE));
        if ($type === null) {
            $product->delete_meta_data(Webinar::META_TYPE);
            return;
        }

        $product->update_meta_data(Webinar::META_TYPE, $type->value);
        $product->set_virtual(true);
        $product->set_sold_individually(true);

        $accessDays = Webinar::parseAccessDays($input(Webinar::META_ACCESS_DAYS));
        $product->update_meta_data(Webinar::META_ACCESS_DAYS, $accessDays !== null ? (string) $accessDays : '');

        $videoId = $input(Webinar::META_BUNNY_VIDEO_ID);
        $product->update_meta_data(Webinar::META_BUNNY_VIDEO_ID, $videoId);

        $startsAt = $this->fromLocalInput($input(Webinar::META_STARTS_AT));
        $product->update_meta_data(Webinar::META_STARTS_AT, $startsAt?->format(Webinar::DATE_FORMAT) ?? '');
        $duration = absint($input(Webinar::META_DURATION));
        $product->update_meta_data(Webinar::META_DURATION, $duration > 0 ? (string) $duration : '');
        $zoomId = (string) preg_replace('/\D/', '', $input(Webinar::META_ZOOM_ID));
        $product->update_meta_data(Webinar::META_ZOOM_ID, $zoomId);
        $zoomKind = ZoomKind::tryFrom($input(Webinar::META_ZOOM_KIND)) ?? ZoomKind::Meeting;
        $product->update_meta_data(Webinar::META_ZOOM_KIND, $zoomKind->value);

        if ($type === WebinarType::Recorded && $videoId === '') {
            \WC_Admin_Meta_Boxes::add_error(
                __('Recorded webinar: the Bunny video ID is missing.', 'namoudusi-webinars')
            );
        }
        if ($type === WebinarType::Live && $startsAt === null) {
            \WC_Admin_Meta_Boxes::add_error(
                __('Live webinar: the start date and time are missing.', 'namoudusi-webinars')
            );
        }
    }

    private function toLocalInput(string $utc): string
    {
        $date = \DateTimeImmutable::createFromFormat(Webinar::DATE_FORMAT, $utc, new \DateTimeZone('UTC'));
        return $date !== false ? $date->setTimezone(wp_timezone())->format(self::LOCAL_INPUT_FORMAT) : '';
    }

    private function fromLocalInput(string $local): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(self::LOCAL_INPUT_FORMAT, $local, wp_timezone());
        return $date !== false ? $date->setTimezone(new \DateTimeZone('UTC')) : null;
    }
}
