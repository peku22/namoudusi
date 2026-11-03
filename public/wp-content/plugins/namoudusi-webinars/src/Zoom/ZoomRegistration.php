<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Zoom;

final class ZoomRegistration
{
    public function __construct(
        public readonly string $registrantId,
        public readonly string $joinUrl,
    ) {
    }
}
