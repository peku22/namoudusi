<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Email;

final class AccessGrantedEmail extends WebinarEmail
{
    public function __construct()
    {
        $this->id = 'nmd_access_granted';
        $this->title = __('Webinar access granted', 'namoudusi-webinars');
        $this->description = __(
            'Sent on new webinar access (purchase or manual grant); for live webinars with the personal Zoom link and a calendar file.',
            'namoudusi-webinars'
        );
        parent::__construct();
    }

    public function get_default_subject(): string // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- WC_Email API.
    {
        return __('Your access to {webinar}', 'namoudusi-webinars');
    }

    public function get_default_heading(): string // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- WC_Email API.
    {
        return __('Your webinar is ready', 'namoudusi-webinars');
    }

    protected function intro(): string
    {
        return __(
            'Thank you! You now have access to the webinar below. You will always find it in your account under My webinars.',
            'namoudusi-webinars'
        );
    }
}
