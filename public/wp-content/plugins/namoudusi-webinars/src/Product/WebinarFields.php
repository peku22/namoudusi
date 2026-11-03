<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Product;

use Namoudusi\Webinars\Documents\Document;
use Namoudusi\Webinars\Documents\DocumentStorage;
use Namoudusi\Webinars\Video\BunnyStream;

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
        add_action('post_edit_form_tag', [$this, 'enableFileUploads']);
    }

    public function enableFileUploads(\WP_Post $post): void
    {
        if ($post->post_type === 'product') {
            echo ' enctype="multipart/form-data"';
        }
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

        $this->renderDocuments(Webinar::documentsFromMeta($product?->get_meta(Webinar::META_DOCUMENTS)));

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

        $previous = Webinar::fromProduct($product);
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

        $zoomChanged = $zoomId !== '' && ($zoomId !== $previous?->zoomId || $zoomKind !== $previous->zoomKind);
        if ($type === WebinarType::Live && $zoomChanged) {
            // Buyers get (re)registered by an async job once the product is saved.
            do_action('nmd_webinar_zoom_changed', $product->get_id());
        }

        $this->saveDocuments($product);

        if ($type === WebinarType::Recorded && $videoId === '') {
            \WC_Admin_Meta_Boxes::add_error(
                __('Recorded webinar: the Bunny video ID is missing.', 'namoudusi-webinars')
            );
        }
        if ($videoId !== '' && !BunnyStream::isValidVideoId($videoId)) {
            \WC_Admin_Meta_Boxes::add_error(__(
                'The Bunny video ID should look like 1b2c3d4e-0000-4a5b-8c9d-0123456789ab (Stream → video → Video ID).',
                'namoudusi-webinars'
            ));
        }
        if ($type === WebinarType::Live && $startsAt === null) {
            \WC_Admin_Meta_Boxes::add_error(
                __('Live webinar: the start date and time are missing.', 'namoudusi-webinars')
            );
        }
    }

    /**
     * @param list<Document> $documents
     */
    private function renderDocuments(array $documents): void
    {
        ?>
        <div class="options_group">
            <p class="form-field">
                <label><?php esc_html_e('Documents', 'namoudusi-webinars'); ?></label>
                <span class="wrap" style="display:block">
                    <?php if ($documents !== []) : ?>
                        <table class="widefat" style="margin-bottom:8px">
                            <thead>
                            <tr>
                                <th><?php esc_html_e('Title', 'namoudusi-webinars'); ?></th>
                                <th><?php esc_html_e('File', 'namoudusi-webinars'); ?></th>
                                <th><?php esc_html_e('Delete', 'namoudusi-webinars'); ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($documents as $document) : ?>
                                <?php $name = 'nmd_documents[' . $document->id . ']'; ?>
                                <tr>
                                    <td>
                                        <input type="text" class="short" style="width:100%"
                                               name="<?php echo esc_attr($name . '[title]'); ?>"
                                               value="<?php echo esc_attr($document->title); ?>">
                                    </td>
                                    <td>
                                        <?php echo esc_html(basename($document->file)); ?>
                                        (<?php echo esc_html((string) size_format($document->size)); ?>)
                                    </td>
                                    <td>
                                        <input type="checkbox" value="1"
                                               name="<?php echo esc_attr($name . '[delete]'); ?>">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <input type="file" name="nmd_document_upload[]" multiple
                           accept="<?php echo esc_attr($this->acceptAttribute()); ?>">
                </span>
                <span class="description" style="display:block;clear:both">
                    <?php esc_html_e(
                        'Only customers with access can download these files; they are not in the media library.',
                        'namoudusi-webinars'
                    ); ?>
                </span>
            </p>
        </div>
        <?php
    }

    private function saveDocuments(\WC_Product $product): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its product nonce before this hook.
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized below.
        $posted = isset($_POST['nmd_documents']) && is_array($_POST['nmd_documents'])
            ? wp_unslash($_POST['nmd_documents'])
            : [];
        // phpcs:enable

        $documents = [];
        foreach (Webinar::documentsFromMeta($product->get_meta(Webinar::META_DOCUMENTS)) as $document) {
            $input = is_array($posted[$document->id] ?? null) ? $posted[$document->id] : [];
            if (!empty($input['delete'])) {
                DocumentStorage::delete($document);
                continue;
            }
            $title = isset($input['title']) ? sanitize_text_field((string) $input['title']) : '';
            $documents[] = $title !== '' ? $document->withTitle($title) : $document;
        }

        foreach ($this->uploadedFiles() as $file) {
            $result = DocumentStorage::store($file, $product->get_id());
            if ($result instanceof Document) {
                $documents[] = $result;
            } else {
                \WC_Admin_Meta_Boxes::add_error(sprintf(
                    /* translators: 1: file name, 2: error message */
                    __('Document "%1$s" was not uploaded: %2$s', 'namoudusi-webinars'),
                    sanitize_file_name($file['name']),
                    $result
                ));
            }
        }

        $product->update_meta_data(
            Webinar::META_DOCUMENTS,
            array_map(static fn (Document $document) => $document->toArray(), $documents)
        );
    }

    /**
     * Normalizes the multi-file input into a list of single $_FILES entries.
     *
     * @return list<array{name: string, type: string, tmp_name: string, error: int, size: int}>
     */
    private function uploadedFiles(): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce: see saveDocuments(); files are validated by wp_handle_upload().
        $upload = $_FILES['nmd_document_upload'] ?? null;
        if (!is_array($upload) || !is_array($upload['name'] ?? null)) {
            return [];
        }
        $files = [];
        foreach ($upload['name'] as $i => $name) {
            if ((int) $upload['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = [
                'name' => (string) $name,
                'type' => (string) $upload['type'][$i],
                'tmp_name' => (string) $upload['tmp_name'][$i],
                'error' => (int) $upload['error'][$i],
                'size' => (int) $upload['size'][$i],
            ];
        }
        return $files;
    }

    private function acceptAttribute(): string
    {
        $extensions = [];
        foreach (array_keys(DocumentStorage::ALLOWED_MIMES) as $pattern) {
            foreach (explode('|', $pattern) as $extension) {
                $extensions[] = '.' . $extension;
            }
        }
        return implode(',', $extensions);
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
