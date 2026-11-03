<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Product;

use Namoudusi\Webinars\Documents\Document;

/**
 * Read model over the webinar meta of a WooCommerce product.
 * A product is a webinar when `_nmd_type` holds a valid type.
 */
final class Webinar
{
    public const META_TYPE = '_nmd_type';
    public const META_BUNNY_VIDEO_ID = '_nmd_bunny_video_id';
    public const META_STARTS_AT = '_nmd_starts_at'; // UTC, Y-m-d H:i:s
    public const META_DURATION = '_nmd_duration';   // minutes
    public const META_ZOOM_ID = '_nmd_zoom_id';
    public const META_ZOOM_KIND = '_nmd_zoom_kind';
    public const META_ACCESS_DAYS = '_nmd_access_days'; // empty = no time limit
    public const META_DOCUMENTS = '_nmd_documents';     // list of Document::toArray()

    public const DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param list<Document> $documents
     */
    private function __construct(
        public readonly int $productId,
        public readonly string $name,
        public readonly WebinarType $type,
        public readonly string $bunnyVideoId,
        public readonly ?\DateTimeImmutable $startsAt,
        public readonly int $durationMinutes,
        public readonly string $zoomId,
        public readonly ZoomKind $zoomKind,
        public readonly ?int $accessDays,
        public readonly array $documents,
    ) {
    }

    public static function fromProduct(?\WC_Product $product): ?self
    {
        if ($product === null) {
            return null;
        }
        $type = WebinarType::tryFrom((string) $product->get_meta(self::META_TYPE));
        if ($type === null) {
            return null;
        }

        $startsAt = (string) $product->get_meta(self::META_STARTS_AT);
        $startsAt = $startsAt !== ''
            ? \DateTimeImmutable::createFromFormat(self::DATE_FORMAT, $startsAt, new \DateTimeZone('UTC'))
            : false;

        return new self(
            $product->get_id(),
            $product->get_name(),
            $type,
            (string) $product->get_meta(self::META_BUNNY_VIDEO_ID),
            $startsAt !== false ? $startsAt : null,
            (int) $product->get_meta(self::META_DURATION),
            (string) $product->get_meta(self::META_ZOOM_ID),
            ZoomKind::tryFrom((string) $product->get_meta(self::META_ZOOM_KIND)) ?? ZoomKind::Meeting,
            self::parseAccessDays((string) $product->get_meta(self::META_ACCESS_DAYS)),
            self::documentsFromMeta($product->get_meta(self::META_DOCUMENTS)),
        );
    }

    /**
     * @return list<Document>
     */
    public static function documentsFromMeta(mixed $meta): array
    {
        $documents = [];
        foreach (is_array($meta) ? $meta : [] as $data) {
            $document = is_array($data) ? Document::fromArray($data) : null;
            if ($document !== null) {
                $documents[] = $document;
            }
        }
        return $documents;
    }

    public function document(string $id): ?Document
    {
        foreach ($this->documents as $document) {
            if ($document->id === $id) {
                return $document;
            }
        }
        return null;
    }

    public static function fromProductId(int $productId): ?self
    {
        $product = wc_get_product($productId);
        return self::fromProduct($product instanceof \WC_Product ? $product : null);
    }

    public static function parseAccessDays(string $value): ?int
    {
        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    public function isLive(): bool
    {
        return $this->type === WebinarType::Live;
    }
}
