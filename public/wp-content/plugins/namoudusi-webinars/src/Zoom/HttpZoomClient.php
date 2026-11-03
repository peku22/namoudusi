<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Zoom;

use Namoudusi\Webinars\Product\ZoomKind;

/**
 * Zoom REST API v2 via a Server-to-Server OAuth app (scopes: meeting:write/read, webinar:write/read).
 */
final class HttpZoomClient implements ZoomClient
{
    private const API = 'https://api.zoom.us/v2';
    private const TOKEN_URL = 'https://zoom.us/oauth/token';
    private const TOKEN_TRANSIENT = 'nmd_zoom_access_token';

    public function __construct(
        private readonly string $accountId,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    public function register(ZoomKind $kind, string $zoomId, string $email, string $firstName, string $lastName): ZoomRegistration
    {
        $response = $this->request('POST', self::path($kind, $zoomId) . '/registrants', [
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
        $registrantId = (string) ($response['registrant_id'] ?? '');
        $joinUrl = (string) ($response['join_url'] ?? '');
        if ($registrantId === '' || $joinUrl === '') {
            throw new ZoomException('Zoom did not return a registrant ID / join URL.');
        }
        return new ZoomRegistration($registrantId, $joinUrl);
    }

    public function cancel(ZoomKind $kind, string $zoomId, string $registrantId, string $email): void
    {
        $this->request('PUT', self::path($kind, $zoomId) . '/registrants/status', [
            'action' => 'cancel',
            'registrants' => [['id' => $registrantId, 'email' => $email]],
        ]);
    }

    public function details(ZoomKind $kind, string $zoomId): array
    {
        return $this->request('GET', self::path($kind, $zoomId));
    }

    private static function path(ZoomKind $kind, string $zoomId): string
    {
        return ($kind === ZoomKind::Webinar ? '/webinars/' : '/meetings/') . rawurlencode($zoomId);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null, bool $retried = false): array
    {
        $args = [
            'method' => $method,
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ];
        if ($body !== null) {
            $args['body'] = (string) wp_json_encode($body);
        }

        $response = wp_remote_request(self::API . $path, $args);
        if (is_wp_error($response)) {
            throw new ZoomException('Zoom request failed: ' . $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $data = is_array($data) ? $data : [];

        if ($status === 401 && !$retried) {
            delete_transient(self::TOKEN_TRANSIENT);
            return $this->request($method, $path, $body, true);
        }
        if ($status < 200 || $status >= 300) {
            throw new ZoomException(sprintf(
                'Zoom %s %s returned HTTP %d: %s',
                $method,
                $path,
                $status,
                (string) ($data['message'] ?? 'no message')
            ), $status);
        }
        return $data;
    }

    private function token(): string
    {
        $cached = get_transient(self::TOKEN_TRANSIENT);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = wp_remote_post(
            self::TOKEN_URL . '?' . http_build_query([
                'grant_type' => 'account_credentials',
                'account_id' => $this->accountId,
            ]),
            [
                'timeout' => 15,
                'headers' => [
                    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
                    'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                ],
            ]
        );
        if (is_wp_error($response)) {
            throw new ZoomException('Zoom OAuth failed: ' . $response->get_error_message());
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $token = is_array($data) ? (string) ($data['access_token'] ?? '') : '';
        if ($token === '') {
            throw new ZoomException(sprintf(
                'Zoom OAuth returned HTTP %d without a token: %s',
                (int) wp_remote_retrieve_response_code($response),
                is_array($data) ? (string) ($data['reason'] ?? $data['error'] ?? '') : ''
            ));
        }
        $ttl = (int) ($data['expires_in'] ?? 3600);
        set_transient(self::TOKEN_TRANSIENT, $token, max(60, $ttl - 120));
        return $token;
    }
}
