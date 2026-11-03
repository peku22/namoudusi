<?php

/**
 * Webinar detail → video player.
 *
 * Override: yourtheme/namoudusi-webinars/myaccount/webinar-video.php
 *
 * @var Namoudusi\Webinars\Product\Webinar $webinar
 * @var string                             $embedUrl          Signed, short-lived Bunny embed URL ('' on error).
 * @var string                             $error             '' | 'not_configured' | 'invalid_video_id'
 * @var bool                               $showDeveloperHint true on the local environment.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<section class="nmd-webinar__section nmd-webinar__video">
    <h3><?php echo esc_html($webinar->isLive() ? __('Recording', 'namoudusi-webinars') : __('Video', 'namoudusi-webinars')); ?></h3>

    <?php if ($embedUrl !== '') : ?>
        <div class="nmd-player">
            <iframe
                src="<?php echo esc_url($embedUrl); ?>"
                title="<?php echo esc_attr($webinar->name); ?>"
                loading="lazy"
                referrerpolicy="origin"
                allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture; fullscreen"
                allowfullscreen></iframe>
        </div>
    <?php elseif ($showDeveloperHint) : ?>
        <div class="nmd-player nmd-player--placeholder">
            <p>
                <strong>Bunny Stream (local):</strong>
                <?php if ($error === 'not_configured') : ?>
                    define NAMOUDUSI_BUNNY_LIBRARY_ID and NAMOUDUSI_BUNNY_TOKEN_KEY in wp-config.php.
                <?php else : ?>
                    the video ID "<?php echo esc_html($webinar->bunnyVideoId); ?>" is not a valid Bunny GUID.
                <?php endif; ?>
            </p>
        </div>
    <?php else : ?>
        <div class="woocommerce-info">
            <?php esc_html_e('The video cannot be played right now. Please try again later or contact us.', 'namoudusi-webinars'); ?>
        </div>
    <?php endif; ?>
</section>
