<?php

/**
 * My Account → My webinars → detail.
 *
 * Override: yourtheme/namoudusi-webinars/myaccount/webinar.php
 *
 * @var Namoudusi\Webinars\Product\Webinar|null      $webinar
 * @var Namoudusi\Webinars\Access\Entitlement|null   $entitlement
 * @var bool                                         $hasAccess
 * @var string                                       $listUrl
 */

declare(strict_types=1);

use Namoudusi\Webinars\Access\AccessFormatter;
use Namoudusi\Webinars\Account\WebinarsEndpoint;
use Namoudusi\Webinars\Access\Clock;

defined('ABSPATH') || exit;

if ($webinar === null || $entitlement === null || !$hasAccess) : ?>
    <div class="woocommerce-error">
        <?php esc_html_e('You do not have access to this webinar.', 'namoudusi-webinars'); ?>
    </div>
    <p>
        <a class="button" href="<?php echo esc_url($listUrl); ?>"><?php esc_html_e('Back to My webinars', 'namoudusi-webinars'); ?></a>
        <?php if ($webinar !== null) : ?>
            <a class="button alt" href="<?php echo esc_url((string) get_permalink($webinar->productId)); ?>">
                <?php esc_html_e('Buy access', 'namoudusi-webinars'); ?>
            </a>
        <?php endif; ?>
    </p>
    <?php
    return;
endif;

$now = Clock::now();
?>
<div class="nmd-webinar">
    <p class="nmd-webinar__back"><a href="<?php echo esc_url($listUrl); ?>">&larr; <?php esc_html_e('My webinars', 'namoudusi-webinars'); ?></a></p>

    <?php if ($webinar->isLive()) : ?>
        <section class="nmd-webinar__section nmd-webinar__live">
            <h3><?php esc_html_e('Live webinar', 'namoudusi-webinars'); ?></h3>
            <?php if ($webinar->startsAt !== null) : ?>
                <p class="nmd-webinar__when">
                    <?php
                    echo esc_html(AccessFormatter::date($webinar->startsAt));
                    if ($webinar->durationMinutes > 0) {
                        /* translators: %d: minutes */
                        echo esc_html(' · ' . sprintf(_n('%d minute', '%d minutes', $webinar->durationMinutes, 'namoudusi-webinars'), $webinar->durationMinutes));
                    }
                    ?>
                </p>
            <?php endif; ?>
            <?php if ($entitlement->zoomJoinUrl !== null && $entitlement->zoomJoinUrl !== '') : ?>
                <p>
                    <a class="button alt wp-element-button" href="<?php echo esc_url($entitlement->zoomJoinUrl); ?>" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e('Join on Zoom', 'namoudusi-webinars'); ?>
                    </a>
                </p>
                <p class="nmd-webinar__hint"><?php esc_html_e('This link is personal – please do not share it.', 'namoudusi-webinars'); ?></p>
            <?php endif; ?>
            <?php if ($webinar->startsAt !== null && $webinar->startsAt > $now) : ?>
                <p><a href="<?php echo esc_url(WebinarsEndpoint::calendarUrl($webinar->productId)); ?>"><?php esc_html_e('Add to calendar (.ics)', 'namoudusi-webinars'); ?></a></p>
            <?php endif; ?>
            <?php if (($entitlement->zoomJoinUrl === null || $entitlement->zoomJoinUrl === '') && ($webinar->startsAt === null || $webinar->startsAt > $now)) : ?>
                <p class="nmd-webinar__hint"><?php esc_html_e('We will add your personal Zoom link here before the webinar starts and send it to you by email.', 'namoudusi-webinars'); ?></p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php
    /**
     * Video player slot (Bunny Stream, filled in by the video module).
     *
     * @param Namoudusi\Webinars\Product\Webinar    $webinar
     * @param Namoudusi\Webinars\Access\Entitlement $entitlement
     */
    do_action('nmd_webinar_video', $webinar, $entitlement);
    ?>

    <?php if ($webinar->documents !== []) : ?>
        <section class="nmd-webinar__section nmd-webinar__documents">
            <h3><?php esc_html_e('Documents', 'namoudusi-webinars'); ?></h3>
            <ul>
                <?php foreach ($webinar->documents as $document) : ?>
                    <li>
                        <a href="<?php echo esc_url(WebinarsEndpoint::url($webinar->productId, $document->id)); ?>">
                            <?php echo esc_html($document->title); ?>
                        </a>
                        <span class="nmd-webinar__size">(<?php echo esc_html(strtoupper(pathinfo($document->file, PATHINFO_EXTENSION)) . ', ' . size_format($document->size)); ?>)</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <p class="nmd-webinar__access">
        <?php
        /* translators: %s: "until <date>" or "no time limit" */
        echo esc_html(sprintf(__('Access: %s', 'namoudusi-webinars'), AccessFormatter::expiry($entitlement->expiresAt)));
        ?>
    </p>
</div>
