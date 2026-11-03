<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Product;

enum WebinarType: string
{
    case Recorded = 'recorded';
    case Live = 'live';
}
