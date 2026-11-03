<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Email;

final class ScheduleChangedEmail extends WebinarEmail
{
    public function __construct()
    {
        $this->id = 'nmd_webinar_rescheduled';
        $this->title = __('Live webinar time changed', 'namoudusi-webinars');
        $this->description = __(
            'Sent to buyers of a live webinar when its start time changes, with an updated calendar file.',
            'namoudusi-webinars'
        );
        parent::__construct();
    }

    public function get_default_subject(): string // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- WC_Email API.
    {
        return __('New date: {webinar} – {start}', 'namoudusi-webinars');
    }

    public function get_default_heading(): string // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- WC_Email API.
    {
        return __('The webinar time has changed', 'namoudusi-webinars');
    }

    protected function intro(): string
    {
        return __(
            'The live webinar you bought has a new date and time. The attached calendar file updates the event in your calendar.',
            'namoudusi-webinars'
        );
    }
}
