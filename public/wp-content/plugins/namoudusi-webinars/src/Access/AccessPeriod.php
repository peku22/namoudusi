<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Access;

/**
 * Access expiry rules. `null` always means "no time limit".
 */
final class AccessPeriod
{
    /**
     * Live webinars count from the event start (or from the purchase, if that is later –
     * e.g. buying the recording after the event). Recorded webinars count from the purchase.
     */
    public static function expiresAt(
        ?int $accessDays,
        \DateTimeImmutable $purchasedAt,
        ?\DateTimeImmutable $eventStartsAt = null,
    ): ?\DateTimeImmutable {
        if ($accessDays === null) {
            return null;
        }
        $from = $eventStartsAt !== null && $eventStartsAt > $purchasedAt ? $eventStartsAt : $purchasedAt;
        return $from->modify(sprintf('+%d days', $accessDays));
    }

    /**
     * Combines still-active access with a new grant: the later expiry wins, no limit beats any date.
     */
    public static function extend(?\DateTimeImmutable $current, ?\DateTimeImmutable $new): ?\DateTimeImmutable
    {
        if ($current === null || $new === null) {
            return null;
        }
        return max($current, $new);
    }
}
