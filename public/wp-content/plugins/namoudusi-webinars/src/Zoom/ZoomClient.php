<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Zoom;

use Namoudusi\Webinars\Product\ZoomKind;

interface ZoomClient
{
    /** @throws ZoomException */
    public function register(ZoomKind $kind, string $zoomId, string $email, string $firstName, string $lastName): ZoomRegistration;

    /** @throws ZoomException */
    public function cancel(ZoomKind $kind, string $zoomId, string $registrantId, string $email): void;

    /**
     * Meeting/webinar details (topic, start_time, settings.approval_type: 0/1 = registration on, 2 = off).
     *
     * @return array<string, mixed>
     * @throws ZoomException
     */
    public function details(ZoomKind $kind, string $zoomId): array;
}
