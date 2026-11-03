<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Email;

final class ZoomLinkEmail extends WebinarEmail
{
    public function __construct()
    {
        $this->id = 'nmd_zoom_link';
        $this->title = __('Webinar Zoom link', 'namoudusi-webinars');
        $this->description = __('Sent when the personal Zoom link becomes available or changes after the purchase.', 'namoudusi-webinars');
        parent::__construct();
    }

    public function get_default_subject(): string // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- WC_Email API.
    {
        return __('Your Zoom link for {webinar}', 'namoudusi-webinars');
    }

    public function get_default_heading(): string // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- WC_Email API.
    {
        return __('Your Zoom link is ready', 'namoudusi-webinars');
    }

    protected function intro(): string
    {
        return __('Your personal link to the live webinar is ready. Please do not share it – it only works for you.', 'namoudusi-webinars');
    }
}
