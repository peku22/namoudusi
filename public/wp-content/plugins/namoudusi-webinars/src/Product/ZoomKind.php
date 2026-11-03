<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Product;

/** Zoom product the live session runs on; the registrant API differs between them. */
enum ZoomKind: string
{
    case Meeting = 'meeting';
    case Webinar = 'webinar';
}
