<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Video;

use Namoudusi\Webinars\Access\Entitlement;
use Namoudusi\Webinars\Account\WebinarsEndpoint;
use Namoudusi\Webinars\Product\Webinar;

/**
 * Renders the Bunny player on the webinar detail page (only reached after the access check).
 */
final class VideoPlayer
{
    public function register(): void
    {
        add_action('nmd_webinar_video', [$this, 'render'], 10, 2);
    }

    public function render(Webinar $webinar, Entitlement $entitlement): void
    {
        if ($webinar->bunnyVideoId === '') {
            return;
        }

        /** Allows tests (and future providers) to swap the signer. */
        $stream = apply_filters('nmd_bunny_stream', BunnyStream::fromConfig());
        $embedUrl = '';
        $error = '';

        if (!$stream instanceof BunnyStream) {
            $error = 'not_configured';
            $this->log(sprintf('Bunny Stream is not configured; product %d cannot play.', $webinar->productId));
        } elseif (!BunnyStream::isValidVideoId($webinar->bunnyVideoId)) {
            $error = 'invalid_video_id';
            $this->log(sprintf('Product %d has an invalid Bunny video ID.', $webinar->productId));
        } else {
            $embedUrl = $stream->embedUrl($webinar->bunnyVideoId, time());
        }

        wc_get_template('myaccount/webinar-video.php', [
            'webinar' => $webinar,
            'embedUrl' => $embedUrl,
            'error' => $error,
            'showDeveloperHint' => wp_get_environment_type() === 'local',
        ], 'namoudusi-webinars/', WebinarsEndpoint::templateDir());
    }

    private function log(string $message): void
    {
        if (wp_get_environment_type() === 'local') {
            return; // the local page shows a developer hint instead
        }
        wc_get_logger()->error($message, ['source' => 'namoudusi-webinars']);
    }
}
