<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Zoom;

use Namoudusi\Webinars\Access\Clock;
use Namoudusi\Webinars\Access\Entitlement;
use Namoudusi\Webinars\Access\EntitlementRepository;
use Namoudusi\Webinars\Log;
use Namoudusi\Webinars\Product\Webinar;
use Namoudusi\Webinars\Product\ZoomKind;

/**
 * Registers buyers of live webinars in Zoom (one personal join link each) via Action Scheduler,
 * re-registers them when the product's Zoom ID changes and cancels registrations on revoke.
 *
 * After a registration attempt it fires `nmd_zoom_registration_done` (Entitlement, string $notify, bool $success)
 * so the e-mail module can send the access e-mail (notify = access) or the link e-mail (notify = link).
 */
final class ZoomRegistrar
{
    public const GROUP = 'namoudusi-webinars';
    public const HOOK_REGISTER = 'nmd_zoom_register';
    public const HOOK_CANCEL = 'nmd_zoom_cancel';
    public const HOOK_SYNC_PRODUCT = 'nmd_zoom_sync_product';

    public const NOTIFY_ACCESS = 'access';
    public const NOTIFY_LINK = 'link';
    public const NOTIFY_NONE = 'none';

    /** Delay in minutes before attempt 2, 3, 4. */
    private const RETRY_DELAYS = [5, 15, 60];

    public function __construct(private readonly EntitlementRepository $entitlements)
    {
    }

    public function register(): void
    {
        add_action('nmd_access_granted', [$this, 'onAccessGranted'], 10, 2);
        add_action('nmd_access_revoked', [$this, 'onAccessRevoked']);
        add_action('nmd_webinar_zoom_changed', [$this, 'scheduleProductSync']);
        add_action(self::HOOK_REGISTER, [$this, 'handleRegister'], 10, 3);
        add_action(self::HOOK_CANCEL, [$this, 'handleCancel'], 10, 4);
        add_action(self::HOOK_SYNC_PRODUCT, [$this, 'handleProductSync']);
    }

    /**
     * True when buyers of this webinar get a personal Zoom link (so the access e-mail waits for it).
     */
    public static function willRegister(Webinar $webinar): bool
    {
        return $webinar->isLive() && $webinar->zoomId !== '' && ZoomClientFactory::create() !== null;
    }

    public function onAccessGranted(Entitlement $entitlement, bool $isNew = true): void
    {
        $webinar = Webinar::fromProductId($entitlement->productId);
        if ($webinar === null || !self::willRegister($webinar) || !$this->needsRegistration($entitlement, $webinar)) {
            return;
        }
        $this->enqueue(self::HOOK_REGISTER, [$entitlement->id, $isNew ? self::NOTIFY_ACCESS : self::NOTIFY_NONE, 1]);
    }

    public function onAccessRevoked(Entitlement $entitlement): void
    {
        if ($entitlement->zoomRegistrantId === null || $entitlement->zoomMeetingId === null) {
            return;
        }
        $webinar = Webinar::fromProductId($entitlement->productId);
        $user = get_user_by('id', $entitlement->userId);
        $this->enqueue(self::HOOK_CANCEL, [
            ($webinar->zoomKind ?? ZoomKind::Meeting)->value,
            $entitlement->zoomMeetingId,
            $entitlement->zoomRegistrantId,
            $user instanceof \WP_User ? $user->user_email : '',
        ]);
        $this->entitlements->update($entitlement->id, [
            'zoom_registrant_id' => null,
            'zoom_join_url' => null,
            'zoom_meeting_id' => null,
        ]);
    }

    public function scheduleProductSync(int $productId): void
    {
        $this->enqueue(self::HOOK_SYNC_PRODUCT, [$productId]);
    }

    public function handleProductSync(int $productId): void
    {
        $webinar = Webinar::fromProductId($productId);
        if ($webinar === null || !self::willRegister($webinar)) {
            return;
        }
        foreach ($this->entitlements->activeForProduct($productId) as $entitlement) {
            if ($this->needsRegistration($entitlement, $webinar)) {
                $this->enqueue(self::HOOK_REGISTER, [$entitlement->id, self::NOTIFY_LINK, 1]);
            }
        }
    }

    public function handleRegister(int $entitlementId, string $notify = self::NOTIFY_NONE, int $attempt = 1): void
    {
        $entitlement = $this->entitlements->findById($entitlementId);
        $webinar = $entitlement !== null ? Webinar::fromProductId($entitlement->productId) : null;
        $client = ZoomClientFactory::create();
        $user = $entitlement !== null ? get_user_by('id', $entitlement->userId) : false;

        if (
            $entitlement === null || $webinar === null || $client === null || !$user instanceof \WP_User
            || !$entitlement->isActive(Clock::now()) || !$webinar->isLive() || $webinar->zoomId === ''
        ) {
            return;
        }
        if (!$this->needsRegistration($entitlement, $webinar)) {
            do_action('nmd_zoom_registration_done', $entitlement, $notify, true);
            return;
        }

        try {
            if ($entitlement->zoomRegistrantId !== null && $entitlement->zoomMeetingId !== null) {
                $this->cancelQuietly($client, $webinar->zoomKind, $entitlement->zoomMeetingId, $entitlement->zoomRegistrantId, $user->user_email);
            }
            [$firstName, $lastName] = self::names($user);
            $registration = $client->register($webinar->zoomKind, $webinar->zoomId, $user->user_email, $firstName, $lastName);
        } catch (ZoomException $e) {
            $this->onRegisterFailed($entitlement, $notify, $attempt, $e);
            return;
        }

        $this->entitlements->update($entitlement->id, [
            'zoom_registrant_id' => $registration->registrantId,
            'zoom_join_url' => $registration->joinUrl,
            'zoom_meeting_id' => $webinar->zoomId,
        ]);
        $updated = $this->entitlements->findById($entitlement->id) ?? $entitlement;
        do_action('nmd_zoom_registration_done', $updated, $notify, true);
    }

    public function handleCancel(string $kind, string $zoomId, string $registrantId, string $email): void
    {
        $client = ZoomClientFactory::create();
        if ($client !== null) {
            $this->cancelQuietly($client, ZoomKind::tryFrom($kind) ?? ZoomKind::Meeting, $zoomId, $registrantId, $email);
        }
    }

    public function needsRegistration(Entitlement $entitlement, Webinar $webinar): bool
    {
        return $webinar->isLive()
            && $webinar->zoomId !== ''
            && ($entitlement->zoomMeetingId !== $webinar->zoomId || (string) $entitlement->zoomJoinUrl === '');
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function names(\WP_User $user): array
    {
        $first = trim((string) ($user->first_name ?: get_user_meta($user->ID, 'billing_first_name', true)));
        $last = trim((string) ($user->last_name ?: get_user_meta($user->ID, 'billing_last_name', true)));
        if ($first === '') {
            $parts = preg_split('/\s+/', trim($user->display_name)) ?: [];
            $first = (string) array_shift($parts);
            $last = $last !== '' ? $last : implode(' ', $parts);
        }
        return [$first !== '' ? $first : $user->user_login, $last];
    }

    private function onRegisterFailed(Entitlement $entitlement, string $notify, int $attempt, ZoomException $e): void
    {
        $delay = self::RETRY_DELAYS[$attempt - 1] ?? null;
        Log::error(sprintf(
            'Zoom registration failed for access #%d (attempt %d): %s',
            $entitlement->id,
            $attempt,
            $e->getMessage()
        ));
        if ($delay !== null) {
            as_schedule_single_action(
                time() + $delay * MINUTE_IN_SECONDS,
                self::HOOK_REGISTER,
                [$entitlement->id, $notify, $attempt + 1],
                self::GROUP
            );
            return;
        }
        // Give up: the customer still gets the access e-mail; the page says the link will follow.
        do_action('nmd_zoom_registration_done', $entitlement, $notify, false);
    }

    private function cancelQuietly(ZoomClient $client, ZoomKind $kind, string $zoomId, string $registrantId, string $email): void
    {
        try {
            $client->cancel($kind, $zoomId, $registrantId, $email);
        } catch (ZoomException $e) {
            Log::error(sprintf('Zoom cancel of registrant %s failed: %s', $registrantId, $e->getMessage()));
        }
    }

    /**
     * @param list<int|string> $args
     */
    private function enqueue(string $hook, array $args): void
    {
        if (!as_has_scheduled_action($hook, $args, self::GROUP)) {
            as_enqueue_async_action($hook, $args, self::GROUP);
        }
    }
}
