<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Cli;

use Namoudusi\Webinars\Product\Webinar;
use Namoudusi\Webinars\Product\ZoomKind;
use Namoudusi\Webinars\Zoom\FakeZoomClient;
use Namoudusi\Webinars\Zoom\ZoomClientFactory;
use Namoudusi\Webinars\Zoom\ZoomException;
use Namoudusi\Webinars\Zoom\ZoomRegistrar;

/**
 * Zoom integration tools.
 */
final class ZoomCommand
{
    public function __construct(private readonly ZoomRegistrar $registrar)
    {
    }

    /**
     * Checks credentials and that registration is enabled for a meeting/webinar.
     *
     * ## OPTIONS
     *
     * <zoom-id>
     * : Meeting or webinar ID (digits, spaces allowed).
     *
     * [--kind=<kind>]
     * : meeting or webinar.
     * ---
     * default: meeting
     * ---
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function check(array $args, array $assoc): void
    {
        $client = ZoomClientFactory::create();
        if ($client === null) {
            \WP_CLI::error('Zoom is not configured (NAMOUDUSI_ZOOM_* constants).');
        }
        if ($client instanceof FakeZoomClient) {
            \WP_CLI::warning('No Zoom credentials – using the local fake client.');
        }
        $zoomId = (string) preg_replace('/\D/', '', $args[0]);
        try {
            $details = $client->details(ZoomKind::tryFrom($assoc['kind'] ?? 'meeting') ?? ZoomKind::Meeting, $zoomId);
        } catch (ZoomException $e) {
            \WP_CLI::error($e->getMessage());
        }
        $approval = (int) ($details['settings']['approval_type'] ?? 2);
        \WP_CLI::log(sprintf('Topic:        %s', (string) ($details['topic'] ?? '')));
        \WP_CLI::log(sprintf('Start:        %s', (string) ($details['start_time'] ?? '–')));
        if ($approval === 2) {
            \WP_CLI::error('Registration is NOT enabled – turn on "Registration: Required" in Zoom.');
        }
        \WP_CLI::success($approval === 0
            ? 'Registration enabled, auto-approve.'
            : 'Registration enabled, but MANUAL approval – set it to automatic.');
    }

    /**
     * Registers all current buyers of a live webinar (e.g. after fixing the Zoom ID).
     *
     * ## OPTIONS
     *
     * <product>
     * : Webinar product ID.
     *
     * @param list<string> $args
     */
    public function sync(array $args): void
    {
        $webinar = Webinar::fromProductId((int) $args[0]);
        if ($webinar === null || !$webinar->isLive()) {
            \WP_CLI::error('Not a live webinar.');
        }
        $this->registrar->scheduleProductSync($webinar->productId);
        \WP_CLI::success('Sync scheduled (Action Scheduler group "namoudusi-webinars").');
    }
}
