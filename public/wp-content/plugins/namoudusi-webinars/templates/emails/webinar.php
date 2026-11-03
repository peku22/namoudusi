<?php

/**
 * Webinar e-mail (HTML): access granted / Zoom link.
 *
 * Override: yourtheme/namoudusi-webinars/emails/webinar.php
 *
 * @var WC_Email                                   $email
 * @var string                                     $email_heading
 * @var string                                     $intro
 * @var Namoudusi\Webinars\Product\Webinar         $webinar
 * @var Namoudusi\Webinars\Access\Entitlement      $entitlement
 * @var string                                     $pageUrl
 * @var string                                     $additional_content
 */

declare(strict_types=1);

use Namoudusi\Webinars\Access\AccessFormatter;

defined('ABSPATH') || exit;

do_action('woocommerce_email_header', $email_heading, $email);
$joinUrl = (string) $entitlement->zoomJoinUrl;
?>
<p><?php echo esc_html($intro); ?></p>

<h2><?php echo esc_html($webinar->name); ?></h2>

<?php if ($webinar->isLive()) : ?>
    <?php if ($webinar->startsAt !== null) : ?>
        <p>
            <strong><?php esc_html_e('When:', 'namoudusi-webinars'); ?></strong>
            <?php echo esc_html(AccessFormatter::date($webinar->startsAt)); ?>
            <?php if ($webinar->durationMinutes > 0) : ?>
                <?php /* translators: %d: minutes */ ?>
                (<?php echo esc_html(sprintf(_n('%d minute', '%d minutes', $webinar->durationMinutes, 'namoudusi-webinars'), $webinar->durationMinutes)); ?>)
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <?php if ($joinUrl !== '') : ?>
        <p>
            <a href="<?php echo esc_url($joinUrl); ?>" style="display:inline-block;padding:12px 20px;background:#2d6a4f;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;">
                <?php esc_html_e('Join on Zoom', 'namoudusi-webinars'); ?>
            </a>
        </p>
        <p><small><?php esc_html_e('This link is personal – please do not share it.', 'namoudusi-webinars'); ?></small></p>
    <?php else : ?>
        <p><?php esc_html_e('We will send you your personal Zoom link before the webinar starts. You will also find it on the webinar page.', 'namoudusi-webinars'); ?></p>
    <?php endif; ?>
    <?php if ($webinar->startsAt !== null) : ?>
        <p><small><?php esc_html_e('The attached calendar file adds the webinar to your calendar.', 'namoudusi-webinars'); ?></small></p>
    <?php endif; ?>
<?php endif; ?>

<p>
    <a href="<?php echo esc_url($pageUrl); ?>"><?php esc_html_e('Open the webinar page', 'namoudusi-webinars'); ?></a>
    <br>
    <?php
    /* translators: %s: "until <date>" or "no time limit" */
    echo esc_html(sprintf(__('Access: %s', 'namoudusi-webinars'), AccessFormatter::expiry($entitlement->expiresAt)));
    ?>
</p>

<?php
if ($additional_content !== '') {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}

do_action('woocommerce_email_footer', $email);
