<?php

/**
 * Webinar e-mail (plain text): access granted / Zoom link.
 *
 * Override: yourtheme/namoudusi-webinars/emails/plain/webinar.php
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

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text e-mail, no HTML context.
$joinUrl = (string) $entitlement->zoomJoinUrl;

echo '= ' . wp_strip_all_tags($email_heading) . " =\n\n";
echo $intro . "\n\n";
echo $webinar->name . "\n";

if ($webinar->isLive()) {
    if ($webinar->startsAt !== null) {
        echo __('When:', 'namoudusi-webinars') . ' ' . AccessFormatter::date($webinar->startsAt) . "\n";
    }
    echo "\n";
    if ($joinUrl !== '') {
        echo __('Join on Zoom', 'namoudusi-webinars') . ': ' . $joinUrl . "\n";
        echo __('This link is personal – please do not share it.', 'namoudusi-webinars') . "\n";
    } else {
        echo __('We will send you your personal Zoom link before the webinar starts. You will also find it on the webinar page.', 'namoudusi-webinars') . "\n";
    }
}

echo "\n" . __('Open the webinar page', 'namoudusi-webinars') . ': ' . $pageUrl . "\n";
/* translators: %s: "until <date>" or "no time limit" */
echo sprintf(__('Access: %s', 'namoudusi-webinars'), AccessFormatter::expiry($entitlement->expiresAt)) . "\n\n";

if ($additional_content !== '') {
    echo wp_strip_all_tags(wptexturize($additional_content)) . "\n\n";
}

echo wp_strip_all_tags((string) apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')));
