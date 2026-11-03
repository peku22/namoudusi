<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Zoom;

use Namoudusi\Webinars\Config;

final class ZoomClientFactory
{
    /**
     * Real client when credentials exist; a fake one on the local environment; otherwise none.
     * Filter `nmd_zoom_client` lets tests inject their own.
     */
    public static function create(): ?ZoomClient
    {
        $client = null;
        if (Config::hasZoom()) {
            $client = new HttpZoomClient(
                Config::get('NAMOUDUSI_ZOOM_ACCOUNT_ID'),
                Config::get('NAMOUDUSI_ZOOM_CLIENT_ID'),
                Config::get('NAMOUDUSI_ZOOM_CLIENT_SECRET'),
            );
        } elseif (wp_get_environment_type() === 'local') {
            $client = new FakeZoomClient();
        }
        $client = apply_filters('nmd_zoom_client', $client);
        return $client instanceof ZoomClient ? $client : null;
    }
}
