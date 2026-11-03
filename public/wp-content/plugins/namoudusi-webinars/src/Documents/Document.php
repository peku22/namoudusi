<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Documents;

/**
 * A file attached to a webinar, stored in the protected folder (path relative to it).
 */
final class Document
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $file,
        public readonly int $size,
        public readonly string $mime,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        if (!isset($data['id'], $data['file']) || !is_string($data['id']) || !is_string($data['file'])) {
            return null;
        }
        return new self(
            $data['id'],
            isset($data['title']) && is_string($data['title']) ? $data['title'] : basename($data['file']),
            $data['file'],
            isset($data['size']) ? (int) $data['size'] : 0,
            isset($data['mime']) && is_string($data['mime']) ? $data['mime'] : 'application/octet-stream',
        );
    }

    /**
     * @return array{id: string, title: string, file: string, size: int, mime: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'file' => $this->file,
            'size' => $this->size,
            'mime' => $this->mime,
        ];
    }

    public function withTitle(string $title): self
    {
        return new self($this->id, $title, $this->file, $this->size, $this->mime);
    }

    /** File name offered to the browser: the title plus the original extension. */
    public function downloadName(): string
    {
        $extension = pathinfo($this->file, PATHINFO_EXTENSION);
        $base = sanitize_file_name($this->title) ?: 'document';
        return $extension !== '' && !str_ends_with(strtolower($base), '.' . strtolower($extension))
            ? $base . '.' . $extension
            : $base;
    }
}
