<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Email;

use Namoudusi\Webinars\Access\Entitlement;
use Namoudusi\Webinars\Product\Webinar;
use Namoudusi\Webinars\Zoom\ZoomRegistrar;

/**
 * Decides which webinar e-mail goes out when:
 * - new access, no Zoom registration needed → access e-mail right away;
 * - new access to a live webinar with Zoom → access e-mail once the personal link exists (or registration gave up);
 * - Zoom link added/changed later → link e-mail.
 */
final class Emails
{
    public const ACCESS_GRANTED = 'nmd_access_granted';
    public const ZOOM_LINK = 'nmd_zoom_link';

    public function register(): void
    {
        add_filter('woocommerce_email_classes', [$this, 'addEmailClasses']);
        add_action('nmd_access_granted', [$this, 'onAccessGranted'], 20, 2);
        add_action('nmd_zoom_registration_done', [$this, 'onZoomRegistrationDone'], 10, 3);
    }

    /**
     * @param array<string, \WC_Email> $emails
     * @return array<string, \WC_Email>
     */
    public function addEmailClasses(array $emails): array
    {
        $emails[self::ACCESS_GRANTED] = new AccessGrantedEmail();
        $emails[self::ZOOM_LINK] = new ZoomLinkEmail();
        return $emails;
    }

    public function onAccessGranted(Entitlement $entitlement, bool $isNew = true): void
    {
        $webinar = Webinar::fromProductId($entitlement->productId);
        if ($isNew && $webinar !== null && !ZoomRegistrar::willRegister($webinar)) {
            self::send(self::ACCESS_GRANTED, $entitlement);
        }
    }

    public function onZoomRegistrationDone(Entitlement $entitlement, string $notify, bool $success): void
    {
        if ($notify === ZoomRegistrar::NOTIFY_ACCESS) {
            self::send(self::ACCESS_GRANTED, $entitlement);
        } elseif ($notify === ZoomRegistrar::NOTIFY_LINK && $success) {
            self::send(self::ZOOM_LINK, $entitlement);
        }
    }

    public static function send(string $key, Entitlement $entitlement): bool
    {
        $email = WC()->mailer()->get_emails()[$key] ?? null;
        return $email instanceof WebinarEmail && $email->trigger($entitlement);
    }
}
