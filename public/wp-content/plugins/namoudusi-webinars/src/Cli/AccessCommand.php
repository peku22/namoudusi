<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Cli;

use Namoudusi\Webinars\Access\AccessFormatter;
use Namoudusi\Webinars\Access\AccessService;
use Namoudusi\Webinars\Access\Clock;
use Namoudusi\Webinars\Access\Entitlement;
use Namoudusi\Webinars\Access\EntitlementRepository;
use Namoudusi\Webinars\Admin\AccessAdmin;
use Namoudusi\Webinars\Product\Webinar;

/**
 * Manage webinar access.
 */
final class AccessCommand
{
    public function __construct(
        private readonly AccessService $access,
        private readonly EntitlementRepository $entitlements,
    ) {
    }

    /**
     * List access records.
     *
     * ## OPTIONS
     *
     * [--customer=<user>]
     * : User ID, login or email (--user is WP-CLI's global "run as" option).
     *
     * [--product=<id>]
     * : Product ID.
     *
     * [--format=<format>]
     * : table, csv, json, yaml.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function list(array $args, array $assoc): void
    {
        $userId = isset($assoc['customer']) ? $this->userId($assoc['customer']) : null;
        $productId = isset($assoc['product']) ? (int) $assoc['product'] : null;
        $now = Clock::now();
        $rows = array_map(static fn (Entitlement $e) => [
            'id' => $e->id,
            'user' => $e->userId,
            'product' => $e->productId,
            'source' => $e->source,
            'order' => $e->orderId ?? '',
            'granted_at' => $e->grantedAt->format('Y-m-d H:i'),
            'expires_at' => $e->expiresAt?->format('Y-m-d H:i') ?? 'unlimited',
            'status' => $e->status($now),
        ], $this->entitlements->all($userId, $productId));

        \WP_CLI\Utils\format_items(
            $assoc['format'] ?? 'table',
            $rows,
            ['id', 'user', 'product', 'source', 'order', 'granted_at', 'expires_at', 'status']
        );
    }

    /**
     * Grant access manually. Without --until the access has no time limit.
     *
     * ## OPTIONS
     *
     * <user>
     * : User ID, login or email.
     *
     * <product>
     * : Webinar product ID.
     *
     * [--until=<date>]
     * : Last day of access (Y-m-d, site timezone).
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function grant(array $args, array $assoc): void
    {
        [$user, $productId] = $args;
        if (Webinar::fromProductId((int) $productId) === null) {
            \WP_CLI::error(sprintf('Product %s is not a webinar.', $productId));
        }
        $until = isset($assoc['until']) ? AccessAdmin::endOfDay($assoc['until']) : null;
        if (isset($assoc['until']) && $until === null) {
            \WP_CLI::error('Invalid --until date, use Y-m-d.');
        }
        $entitlement = $this->access->grant(
            $this->userId($user),
            (int) $productId,
            $until,
            Entitlement::SOURCE_MANUAL,
            extend: false
        );
        $expiry = AccessFormatter::expiry($entitlement->expiresAt);
        \WP_CLI::success(sprintf('Access #%d: %s.', $entitlement->id, $expiry));
    }

    /**
     * Revoke access.
     *
     * ## OPTIONS
     *
     * <user>
     * : User ID, login or email.
     *
     * <product>
     * : Webinar product ID.
     *
     * @param list<string> $args
     */
    public function revoke(array $args): void
    {
        [$user, $productId] = $args;
        $entitlement = $this->entitlements->find($this->userId($user), (int) $productId);
        if ($entitlement === null) {
            \WP_CLI::error('No access record found.');
        }
        $this->access->revoke($entitlement);
        \WP_CLI::success(sprintf('Access #%d revoked.', $entitlement->id));
    }

    private function userId(string $user): int
    {
        $field = is_numeric($user) ? 'id' : (is_email($user) ? 'email' : 'login');
        $found = get_user_by($field, $user);
        if (!$found instanceof \WP_User) {
            \WP_CLI::error(sprintf('User "%s" not found.', $user));
        }
        return $found->ID;
    }
}
