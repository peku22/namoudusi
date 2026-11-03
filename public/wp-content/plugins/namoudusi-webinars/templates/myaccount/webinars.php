<?php

/**
 * My Account → My webinars (list).
 *
 * Override: yourtheme/namoudusi-webinars/myaccount/webinars.php
 *
 * @var list<array{webinar: Namoudusi\Webinars\Product\Webinar, entitlement: Namoudusi\Webinars\Access\Entitlement, active: bool, url: string}> $items
 */

declare(strict_types=1);

use Namoudusi\Webinars\Access\AccessFormatter;

defined('ABSPATH') || exit;

if ($items === []) : ?>
    <div class="woocommerce-info">
        <?php esc_html_e('You have no webinars yet.', 'namoudusi-webinars'); ?>
        <a class="button wc-forward" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>">
            <?php esc_html_e('Browse webinars', 'namoudusi-webinars'); ?>
        </a>
    </div>
    <?php
    return;
endif;
?>
<ul class="nmd-webinars">
    <?php foreach ($items as $item) : ?>
        <?php
        $webinar = $item['webinar'];
        $entitlement = $item['entitlement'];
        ?>
        <li class="nmd-webinars__item<?php echo $item['active'] ? '' : ' nmd-webinars__item--expired'; ?>">
            <a class="nmd-webinars__title" href="<?php echo esc_url($item['url']); ?>">
                <?php echo esc_html($webinar->name); ?>
            </a>
            <span class="nmd-webinars__meta">
                <?php if ($webinar->isLive()) : ?>
                    <?php
                    echo esc_html(
                        $webinar->startsAt !== null
                            /* translators: %s: date and time */
                            ? sprintf(__('Live webinar · %s', 'namoudusi-webinars'), AccessFormatter::date($webinar->startsAt))
                            : __('Live webinar', 'namoudusi-webinars')
                    );
                    ?>
                <?php else : ?>
                    <?php esc_html_e('Recorded webinar', 'namoudusi-webinars'); ?>
                <?php endif; ?>
            </span>
            <span class="nmd-webinars__access">
                <?php if ($item['active']) : ?>
                    <?php
                    /* translators: %s: "until <date>" or "no time limit" */
                    echo esc_html(sprintf(__('Access: %s', 'namoudusi-webinars'), AccessFormatter::expiry($entitlement->expiresAt)));
                    ?>
                <?php else : ?>
                    <?php esc_html_e('Access has expired.', 'namoudusi-webinars'); ?>
                    <a href="<?php echo esc_url($item['url']); ?>"><?php esc_html_e('Buy again', 'namoudusi-webinars'); ?></a>
                <?php endif; ?>
            </span>
        </li>
    <?php endforeach; ?>
</ul>
