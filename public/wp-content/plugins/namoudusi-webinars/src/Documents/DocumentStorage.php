<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Documents;

/**
 * Protected storage for webinar documents: wp-content/uploads/namoudusi-private/{product_id}/.
 * Apache denies direct access; files are only streamed by DocumentController after an access check.
 */
final class DocumentStorage
{
    public const DIRECTORY = 'namoudusi-private';

    /** Extension pattern => MIME type accepted for upload. */
    public const ALLOWED_MIMES = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'txt' => 'text/plain',
        'epub' => 'application/epub+zip',
        'mp3' => 'audio/mpeg',
        'jpg|jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'zip' => 'application/zip',
    ];

    public static function baseDir(): string
    {
        return wp_upload_dir(null, false)['basedir'] . '/' . self::DIRECTORY;
    }

    /**
     * Creates the folder with deny rules (idempotent).
     */
    public static function ensureProtected(): void
    {
        $dir = self::baseDir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $rules = [
            '.htaccess' => "# Webinar documents are served only through WordPress after an access check.\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n",
            'index.php' => "<?php\n// Silence is golden.\n",
        ];
        foreach ($rules as $name => $content) {
            if (!is_file($dir . '/' . $name)) {
                file_put_contents($dir . '/' . $name, $content); // phpcs:ignore WordPress.WP.AlternativeFunctions
            }
        }
    }

    /**
     * Stores an uploaded file for a product.
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file Entry from $_FILES.
     * @param bool $sideload true for files not uploaded via HTTP (tests, imports).
     * @return Document|string Document on success, error message otherwise.
     */
    public static function store(
        array $file,
        int $productId,
        string $title = '',
        bool $sideload = false,
    ): Document|string {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        self::ensureProtected();

        $toProductDir = static function (array $dirs) use ($productId): array {
            $subdir = '/' . self::DIRECTORY . '/' . $productId;
            $dirs['subdir'] = $subdir;
            $dirs['path'] = $dirs['basedir'] . $subdir;
            $dirs['url'] = $dirs['baseurl'] . $subdir;
            return $dirs;
        };
        add_filter('upload_dir', $toProductDir);
        $overrides = ['test_form' => false, 'mimes' => self::ALLOWED_MIMES];
        $result = $sideload ? wp_handle_sideload($file, $overrides) : wp_handle_upload($file, $overrides);
        remove_filter('upload_dir', $toProductDir);

        if (isset($result['error'])) {
            return (string) $result['error'];
        }

        $title = trim($title) !== '' ? trim($title) : pathinfo($file['name'], PATHINFO_FILENAME);
        return new Document(
            wp_generate_uuid4(),
            $title,
            $productId . '/' . basename($result['file']),
            (int) filesize($result['file']),
            $result['type']
        );
    }

    public static function delete(Document $document): void
    {
        $path = self::path($document);
        if ($path !== null) {
            wp_delete_file($path);
        }
    }

    /**
     * Removes all documents of a product (called when the product is deleted permanently).
     */
    public static function deleteProductDir(int $productId): void
    {
        $dir = self::baseDir() . '/' . $productId;
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            wp_delete_file($file);
        }
        @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
    }

    /**
     * Absolute path of an existing document file, or null if it is missing or outside the protected folder.
     */
    public static function path(Document $document): ?string
    {
        return self::resolve(self::baseDir(), $document->file);
    }

    public static function resolve(string $baseDir, string $relative): ?string
    {
        $base = realpath($baseDir);
        $path = realpath($baseDir . '/' . $relative);
        if ($base === false || $path === false || !is_file($path)) {
            return null;
        }
        return str_starts_with($path, $base . DIRECTORY_SEPARATOR) ? $path : null;
    }
}
