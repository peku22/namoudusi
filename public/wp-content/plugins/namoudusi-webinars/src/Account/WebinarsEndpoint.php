<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Account;

use Namoudusi\Webinars\Access\AccessService;
use Namoudusi\Webinars\Access\Clock;
use Namoudusi\Webinars\Access\Entitlement;
use Namoudusi\Webinars\Access\EntitlementRepository;
use Namoudusi\Webinars\Documents\Document;
use Namoudusi\Webinars\Documents\DocumentStorage;
use Namoudusi\Webinars\Product\Webinar;

/**
 * My Account → "My webinars": list, detail page and document downloads.
 *
 *   /muj-ucet/webinare/                      list
 *   /muj-ucet/webinare/{product}/            detail
 *   /muj-ucet/webinare/{product}/dokument/{document}/   download
 */
final class WebinarsEndpoint
{
    public const ENDPOINT = 'nmd-webinars';
    public const SLUG = 'webinare';
    private const REWRITE_VERSION_OPTION = 'nmd_rewrite_version';
    private const REWRITE_VERSION = '1';

    public function __construct(
        private readonly AccessService $access,
        private readonly EntitlementRepository $entitlements,
    ) {
    }

    public function register(): void
    {
        add_filter('woocommerce_get_query_vars', [$this, 'addQueryVar']);
        add_action('init', [$this, 'flushRewriteRulesOnce'], 20);
        add_filter('woocommerce_account_menu_items', [$this, 'addMenuItem']);
        add_filter('woocommerce_endpoint_' . self::ENDPOINT . '_title', [$this, 'title']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [$this, 'render']);
        add_action('template_redirect', [$this, 'serveDocument']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueStyles']);
    }

    public static function url(int $productId = 0, string $documentId = ''): string
    {
        $value = $productId > 0 ? (string) $productId : '';
        if ($documentId !== '') {
            $value .= '/dokument/' . $documentId;
        }
        return wc_get_endpoint_url(self::ENDPOINT, $value, wc_get_page_permalink('myaccount'));
    }

    /**
     * @param array<string, string> $vars
     * @return array<string, string>
     */
    public function addQueryVar(array $vars): array
    {
        $vars[self::ENDPOINT] = self::SLUG;
        return $vars;
    }

    public function flushRewriteRulesOnce(): void
    {
        if (get_option(self::REWRITE_VERSION_OPTION) !== self::REWRITE_VERSION) {
            flush_rewrite_rules(false);
            update_option(self::REWRITE_VERSION_OPTION, self::REWRITE_VERSION, true);
        }
    }

    /**
     * @param array<string, string> $items
     * @return array<string, string>
     */
    public function addMenuItem(array $items): array
    {
        $result = [];
        foreach ($items as $key => $label) {
            $result[$key] = $label;
            if ($key === 'orders') {
                $result[self::ENDPOINT] = __('My webinars', 'namoudusi-webinars');
            }
        }
        if (!isset($result[self::ENDPOINT])) {
            $result = [self::ENDPOINT => __('My webinars', 'namoudusi-webinars')] + $result;
        }
        return $result;
    }

    public function title(): string
    {
        $webinar = $this->requestedWebinar();
        return $webinar !== null ? $webinar->name : __('My webinars', 'namoudusi-webinars');
    }

    public function render(mixed $value = ''): void
    {
        $userId = get_current_user_id();
        $productId = $this->parseProductId((string) $value);

        if ($productId === null) {
            wc_get_template('myaccount/webinars.php', [
                'items' => $this->listItems($userId),
            ], 'namoudusi-webinars/', self::templateDir());
            return;
        }

        $webinar = Webinar::fromProductId($productId);
        $entitlement = $this->entitlements->find($userId, $productId);
        wc_get_template('myaccount/webinar.php', [
            'webinar' => $webinar,
            'entitlement' => $entitlement,
            'hasAccess' => $webinar !== null && $this->access->hasAccess($userId, $productId),
            'listUrl' => self::url(),
        ], 'namoudusi-webinars/', self::templateDir());
    }

    public function serveDocument(): void
    {
        $value = get_query_var(self::ENDPOINT, null);
        if (!is_string($value) || !is_account_page()) {
            return;
        }
        if (preg_match('#^(\d+)/dokument/([a-f0-9-]{36})/?$#', $value, $match) !== 1) {
            return;
        }

        if (!is_user_logged_in()) {
            wp_safe_redirect(wc_get_page_permalink('myaccount'));
            exit;
        }
        $productId = (int) $match[1];
        $webinar = Webinar::fromProductId($productId);
        $document = $webinar?->document($match[2]);
        $path = $document !== null ? DocumentStorage::path($document) : null;

        if ($webinar === null || $document === null || $path === null) {
            $this->deny(404, __('The document does not exist.', 'namoudusi-webinars'));
        }
        if (!$this->access->hasAccess(get_current_user_id(), $productId)) {
            $this->deny(403, __('You do not have access to this document.', 'namoudusi-webinars'));
        }
        $this->stream($document, $path);
    }

    public function enqueueStyles(): void
    {
        if (is_account_page()) {
            wp_enqueue_style(
                'namoudusi-webinars-account',
                plugins_url('assets/account.css', NMD_WEBINARS_FILE),
                [],
                NMD_WEBINARS_VERSION
            );
        }
    }

    public static function templateDir(): string
    {
        return plugin_dir_path(NMD_WEBINARS_FILE) . 'templates/';
    }

    /**
     * Active first (upcoming live by start, then newest purchases), expired last; revoked access is hidden.
     *
     * @return list<array{webinar: Webinar, entitlement: Entitlement, active: bool, url: string}>
     */
    private function listItems(int $userId): array
    {
        $now = Clock::now();
        $items = [];
        foreach ($this->entitlements->forUser($userId) as $entitlement) {
            $webinar = Webinar::fromProductId($entitlement->productId);
            if ($webinar === null || $entitlement->revokedAt !== null) {
                continue;
            }
            $active = $entitlement->isActive($now);
            $items[] = [
                'webinar' => $webinar,
                'entitlement' => $entitlement,
                'active' => $active,
                'url' => $active ? self::url($webinar->productId) : (string) get_permalink($webinar->productId),
            ];
        }

        $sortKey = static function (array $item) use ($now): array {
            $webinar = $item['webinar'];
            $upcoming = $webinar->isLive() && $webinar->startsAt !== null && $webinar->startsAt > $now;
            return [
                $item['active'] ? 0 : 1,
                $upcoming ? 0 : 1,
                $upcoming ? $webinar->startsAt->getTimestamp() : -$item['entitlement']->grantedAt->getTimestamp(),
            ];
        };
        usort($items, static fn (array $a, array $b): int => $sortKey($a) <=> $sortKey($b));
        return $items;
    }

    private function requestedWebinar(): ?Webinar
    {
        $productId = $this->parseProductId((string) get_query_var(self::ENDPOINT));
        return $productId !== null ? Webinar::fromProductId($productId) : null;
    }

    private function parseProductId(string $value): ?int
    {
        return preg_match('#^(\d+)/?$#', $value, $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * @return never
     */
    private function deny(int $status, string $message): void
    {
        wp_die(esc_html($message), esc_html(get_bloginfo('name')), [
            'response' => $status, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTTP status code.
            'link_url' => esc_url(self::url()),
            'link_text' => esc_html__('Back to My webinars', 'namoudusi-webinars'),
        ]);
    }

    /**
     * @return never
     */
    private function stream(Document $document, string $path): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: ' . $document->mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . $document->downloadName() . '"');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        readfile($path); // phpcs:ignore WordPress.WP.AlternativeFunctions
        exit;
    }
}
