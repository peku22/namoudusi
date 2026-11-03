<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Access;

/**
 * Grants and revokes webinar access. Other modules react via the
 * `nmd_access_granted` and `nmd_access_revoked` actions (both receive the Entitlement).
 */
final class AccessService
{
    public function __construct(private readonly EntitlementRepository $entitlements)
    {
    }

    /**
     * @param bool $extend true: still-active access keeps the later expiry (purchases);
     *                     false: the given expiry replaces it (manual grants by an admin).
     */
    public function grant(
        int $userId,
        int $productId,
        ?\DateTimeImmutable $expiresAt,
        string $source,
        ?int $orderId = null,
        ?int $orderItemId = null,
        bool $extend = true,
    ): Entitlement {
        $now = Clock::now();
        $existing = $this->entitlements->find($userId, $productId);

        if ($existing === null) {
            $id = $this->entitlements->create($userId, $productId, $source, $orderId, $orderItemId, $now, $expiresAt);
        } else {
            $id = $existing->id;
            $wasActive = $existing->isActive($now);
            $this->entitlements->update($id, [
                'source' => $source,
                'order_id' => $orderId,
                'order_item_id' => $orderItemId,
                'granted_at' => $wasActive ? $existing->grantedAt : $now,
                'expires_at' => $wasActive && $extend
                    ? AccessPeriod::extend($existing->expiresAt, $expiresAt)
                    : $expiresAt,
                'revoked_at' => null,
            ]);
        }

        $entitlement = $this->entitlements->findById($id);
        if ($entitlement === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- integer, not rendered as HTML.
            throw new \RuntimeException(sprintf('Entitlement %d could not be saved.', $id));
        }
        do_action('nmd_access_granted', $entitlement);
        return $entitlement;
    }

    public function revoke(Entitlement $entitlement): void
    {
        if ($entitlement->revokedAt !== null) {
            return;
        }
        $this->entitlements->update($entitlement->id, ['revoked_at' => Clock::now()]);
        do_action('nmd_access_revoked', $this->entitlements->findById($entitlement->id) ?? $entitlement);
    }

    public function hasAccess(int $userId, int $productId): bool
    {
        if ($userId === 0) {
            return false;
        }
        return $this->entitlements->find($userId, $productId)?->isActive(Clock::now()) ?? false;
    }
}
