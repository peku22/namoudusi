<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Access;

final class Entitlement
{
    public const SOURCE_ORDER = 'order';
    public const SOURCE_MANUAL = 'manual';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';

    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly int $productId,
        public readonly ?int $orderId,
        public readonly ?int $orderItemId,
        public readonly string $source,
        public readonly \DateTimeImmutable $grantedAt,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly ?\DateTimeImmutable $revokedAt,
        public readonly ?string $zoomRegistrantId,
        public readonly ?string $zoomJoinUrl,
    ) {
    }

    public function status(\DateTimeImmutable $now): string
    {
        if ($this->revokedAt !== null) {
            return self::STATUS_REVOKED;
        }
        if ($this->expiresAt !== null && $this->expiresAt <= $now) {
            return self::STATUS_EXPIRED;
        }
        return self::STATUS_ACTIVE;
    }

    public function isActive(\DateTimeImmutable $now): bool
    {
        return $this->status($now) === self::STATUS_ACTIVE;
    }
}
