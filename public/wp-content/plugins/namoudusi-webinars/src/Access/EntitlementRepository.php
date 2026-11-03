<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Access;

use Namoudusi\Webinars\Database\Tables;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table, no WP API exists for it.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only the table name is interpolated.

/**
 * All SQL for the entitlements table. Dates are stored in UTC.
 */
final class EntitlementRepository
{
    private const DATE = 'Y-m-d H:i:s';

    public function find(int $userId, int $productId): ?Entitlement
    {
        global $wpdb;
        $table = Tables::entitlements();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE user_id = %d AND product_id = %d", $userId, $productId)
        );
        return is_object($row) ? $this->hydrate($row) : null;
    }

    public function findById(int $id): ?Entitlement
    {
        global $wpdb;
        $table = Tables::entitlements();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
        return is_object($row) ? $this->hydrate($row) : null;
    }

    /**
     * @return list<Entitlement>
     */
    public function forUser(int $userId): array
    {
        global $wpdb;
        $table = Tables::entitlements();
        return $this->hydrateAll($wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE user_id = %d ORDER BY granted_at DESC", $userId)
        ));
    }

    /**
     * Active (not revoked, not expired) access records of a product.
     *
     * @return list<Entitlement>
     */
    public function activeForProduct(int $productId): array
    {
        global $wpdb;
        $table = Tables::entitlements();
        return $this->hydrateAll($wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE product_id = %d AND revoked_at IS NULL
             AND (expires_at IS NULL OR expires_at > %s) ORDER BY id",
            $productId,
            Clock::now()->format(self::DATE)
        )));
    }

    /**
     * @return list<Entitlement>
     */
    public function forOrder(int $orderId): array
    {
        global $wpdb;
        $table = Tables::entitlements();
        return $this->hydrateAll($wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d ORDER BY id", $orderId)
        ));
    }

    /**
     * @return list<Entitlement>
     */
    public function all(?int $userId = null, ?int $productId = null): array
    {
        global $wpdb;
        $table = Tables::entitlements();
        // 0 = filter not applied.
        $user = $userId ?? 0;
        $product = $productId ?? 0;
        return $this->hydrateAll($wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE (%d = 0 OR user_id = %d) AND (%d = 0 OR product_id = %d) ORDER BY id",
            $user,
            $user,
            $product,
            $product
        )));
    }

    public function create(
        int $userId,
        int $productId,
        string $source,
        ?int $orderId,
        ?int $orderItemId,
        \DateTimeImmutable $grantedAt,
        ?\DateTimeImmutable $expiresAt,
    ): int {
        global $wpdb;
        $now = Clock::now()->format(self::DATE);
        $wpdb->insert(Tables::entitlements(), [
            'user_id' => $userId,
            'product_id' => $productId,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'source' => $source,
            'granted_at' => $grantedAt->format(self::DATE),
            'expires_at' => $expiresAt?->format(self::DATE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, int|string|\DateTimeImmutable|null> $fields Column => value; dates are converted.
     */
    public function update(int $id, array $fields): void
    {
        global $wpdb;
        $fields['updated_at'] = Clock::now();
        $data = array_map(
            fn (int|string|\DateTimeImmutable|null $value) => $value instanceof \DateTimeImmutable
                ? $value->format(self::DATE)
                : $value,
            $fields
        );
        $wpdb->update(Tables::entitlements(), $data, ['id' => $id]);
    }

    /**
     * @param array<mixed> $rows
     * @return list<Entitlement>
     */
    private function hydrateAll(array $rows): array
    {
        return array_values(array_map(fn (object $row) => $this->hydrate($row), $rows));
    }

    private function hydrate(object $row): Entitlement
    {
        return new Entitlement(
            (int) $row->id,
            (int) $row->user_id,
            (int) $row->product_id,
            $row->order_id !== null ? (int) $row->order_id : null,
            $row->order_item_id !== null ? (int) $row->order_item_id : null,
            (string) $row->source,
            $this->date((string) $row->granted_at) ?? Clock::now(),
            $this->date($row->expires_at),
            $this->date($row->revoked_at),
            $row->zoom_registrant_id,
            $row->zoom_join_url,
            $row->zoom_meeting_id ?? null,
        );
    }

    private function date(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat(self::DATE, $value, new \DateTimeZone('UTC'));
        return $date !== false ? $date : null;
    }
}
