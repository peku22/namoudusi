<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Calendar;

use Namoudusi\Webinars\Access\Clock;
use Namoudusi\Webinars\Access\Entitlement;
use Namoudusi\Webinars\Account\WebinarsEndpoint;
use Namoudusi\Webinars\Product\Webinar;

/**
 * Personal .ics for a live webinar (contains the buyer's own join link).
 */
final class WebinarCalendar
{
    public static function forEntitlement(Webinar $webinar, Entitlement $entitlement): ?string
    {
        if (!$webinar->isLive() || $webinar->startsAt === null) {
            return null;
        }
        $pageUrl = WebinarsEndpoint::url($webinar->productId);
        $joinUrl = (string) $entitlement->zoomJoinUrl;
        $description = $joinUrl !== ''
            /* translators: 1: personal Zoom link, 2: webinar page URL */
            ? sprintf(__("Join: %1\$s\nWebinar page: %2\$s", 'namoudusi-webinars'), $joinUrl, $pageUrl)
            /* translators: %s: webinar page URL */
            : sprintf(__("Your personal Zoom link will be on the webinar page: %s", 'namoudusi-webinars'), $pageUrl);

        return Ics::event(
            sprintf('webinar-%d-%d@%s', $webinar->productId, $entitlement->userId, wp_parse_url(home_url(), PHP_URL_HOST)),
            $webinar->startsAt,
            $webinar->durationMinutes,
            $webinar->name,
            $description,
            $joinUrl !== '' ? $joinUrl : 'Zoom',
            $pageUrl,
            time(),
            Clock::now(),
        );
    }

    public static function fileName(Webinar $webinar): string
    {
        return (sanitize_title($webinar->name) ?: 'webinar') . '.ics';
    }
}
