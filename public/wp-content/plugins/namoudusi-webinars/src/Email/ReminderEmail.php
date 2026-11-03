<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Email;

use Namoudusi\Webinars\Access\Entitlement;
use Namoudusi\Webinars\Schedule\Reminders;

final class ReminderEmail extends WebinarEmail
{
    private string $kind = Reminders::KIND_24H;

    public function __construct()
    {
        $this->id = 'nmd_webinar_reminder';
        $this->title = __('Live webinar reminder', 'namoudusi-webinars');
        $this->description = __('Sent to buyers of a live webinar 24 hours and 1 hour before it starts.', 'namoudusi-webinars');
        parent::__construct();
    }

    public function triggerReminder(string $kind, Entitlement $entitlement): bool
    {
        $this->kind = $kind;
        return $this->trigger($entitlement);
    }

    public function get_default_subject(): string // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- WC_Email API.
    {
        return __('Reminder: {webinar} starts {start}', 'namoudusi-webinars');
    }

    public function get_default_heading(): string // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- WC_Email API.
    {
        return __('Your webinar starts soon', 'namoudusi-webinars');
    }

    protected function intro(): string
    {
        return $this->kind === Reminders::KIND_1H
            ? __('Your webinar starts in an hour. Join with your personal link below – we look forward to seeing you.', 'namoudusi-webinars')
            : __('Your webinar starts tomorrow. Here is everything you need to join.', 'namoudusi-webinars');
    }
}
