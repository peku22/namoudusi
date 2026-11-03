<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Email;

use Namoudusi\Webinars\Access\Entitlement;
use Namoudusi\Webinars\Account\WebinarsEndpoint;
use Namoudusi\Webinars\Calendar\WebinarCalendar;
use Namoudusi\Webinars\Product\Webinar;

/**
 * Base for webinar e-mails: one template, personal .ics attached for live webinars.
 * Editable under WooCommerce → Settings → E-mails.
 */
abstract class WebinarEmail extends \WC_Email
{
    protected ?Webinar $webinar = null;
    protected ?Entitlement $entitlement = null;
    private ?string $icsPath = null;

    public function __construct()
    {
        $this->customer_email = true;
        $this->template_base = WebinarsEndpoint::templateDir();
        $this->template_html = 'emails/webinar.php';
        $this->template_plain = 'emails/plain/webinar.php';
        $this->placeholders = ['{webinar}' => ''];
        parent::__construct();
    }

    /** Intro paragraph above the webinar details. */
    abstract protected function intro(): string;

    public function trigger(Entitlement $entitlement): bool
    {
        $this->setup_locale();
        $user = get_user_by('id', $entitlement->userId);
        $this->webinar = Webinar::fromProductId($entitlement->productId);
        $this->entitlement = $entitlement;
        $sent = false;

        if ($user instanceof \WP_User && $this->webinar !== null) {
            $this->object = $user;
            $this->recipient = $user->user_email;
            $this->placeholders['{webinar}'] = $this->webinar->name;

            if ($this->is_enabled() && $this->get_recipient()) {
                $this->icsPath = $this->writeIcs();
                $sent = $this->send(
                    $this->get_recipient(),
                    $this->get_subject(),
                    $this->get_content(),
                    $this->get_headers(),
                    $this->get_attachments()
                );
                if ($this->icsPath !== null) {
                    wp_delete_file($this->icsPath);
                    $this->icsPath = null;
                }
            }
        }
        $this->restore_locale();
        return $sent;
    }

    /**
     * @return list<string>
     */
    public function get_attachments(): array // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- WC_Email API.
    {
        $attachments = array_values((array) parent::get_attachments());
        if ($this->icsPath !== null) {
            $attachments[] = $this->icsPath;
        }
        return $attachments;
    }

    public function get_content_html(): string // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- WC_Email API.
    {
        return wc_get_template_html($this->template_html, $this->templateArgs(false), 'namoudusi-webinars/', $this->template_base);
    }

    public function get_content_plain(): string // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- WC_Email API.
    {
        return wc_get_template_html($this->template_plain, $this->templateArgs(true), 'namoudusi-webinars/', $this->template_base);
    }

    /**
     * @return array<string, mixed>
     */
    private function templateArgs(bool $plainText): array
    {
        return [
            'email' => $this,
            'email_heading' => $this->get_heading(),
            'intro' => $this->intro(),
            'webinar' => $this->webinar,
            'entitlement' => $this->entitlement,
            'pageUrl' => $this->webinar !== null ? WebinarsEndpoint::url($this->webinar->productId) : '',
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin' => false,
            'plain_text' => $plainText,
        ];
    }

    private function writeIcs(): ?string
    {
        if ($this->webinar === null || $this->entitlement === null) {
            return null;
        }
        $ics = WebinarCalendar::forEntitlement($this->webinar, $this->entitlement);
        if ($ics === null) {
            return null;
        }
        // A readable file name in the e-mail client: <temp dir>/<random>/<webinar>.ics
        $dir = trailingslashit(get_temp_dir()) . 'nmd-' . wp_generate_password(12, false);
        if (!wp_mkdir_p($dir)) {
            return null;
        }
        $path = $dir . '/' . WebinarCalendar::fileName($this->webinar);
        file_put_contents($path, $ics); // phpcs:ignore WordPress.WP.AlternativeFunctions
        register_shutdown_function(static function () use ($dir): void {
            @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
        });
        return $path;
    }
}
