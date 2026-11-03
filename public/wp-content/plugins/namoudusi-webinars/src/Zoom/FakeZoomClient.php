<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Zoom;

use Namoudusi\Webinars\Log;
use Namoudusi\Webinars\Product\ZoomKind;

/**
 * Local stand-in for Zoom: deterministic registrants, nothing leaves the machine.
 */
final class FakeZoomClient implements ZoomClient
{
    /** @var list<array{0: string, 1: string, 2: string}> Calls made in this request (for tests). */
    public static array $calls = [];

    public function register(ZoomKind $kind, string $zoomId, string $email, string $firstName, string $lastName): ZoomRegistration
    {
        $registrantId = 'fake-' . substr(md5($zoomId . '|' . strtolower($email)), 0, 16);
        self::$calls[] = ['register', $zoomId, $email];
        Log::info(sprintf('Fake Zoom: registered %s for %s %s.', $email, $kind->value, $zoomId));
        return new ZoomRegistration($registrantId, sprintf('https://zoom.us/w/%s?tk=%s', $zoomId, $registrantId));
    }

    public function cancel(ZoomKind $kind, string $zoomId, string $registrantId, string $email): void
    {
        self::$calls[] = ['cancel', $zoomId, $email];
        Log::info(sprintf('Fake Zoom: cancelled %s for %s %s.', $email, $kind->value, $zoomId));
    }

    public function details(ZoomKind $kind, string $zoomId): array
    {
        return ['id' => $zoomId, 'topic' => 'Fake ' . $kind->value, 'settings' => ['approval_type' => 0]];
    }
}
