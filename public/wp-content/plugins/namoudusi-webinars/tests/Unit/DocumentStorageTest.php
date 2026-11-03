<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Tests\Unit;

use Namoudusi\Webinars\Documents\DocumentStorage;
use PHPUnit\Framework\TestCase;

final class DocumentStorageTest extends TestCase
{
    private string $base;
    private string $outside;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/nmd-test-' . bin2hex(random_bytes(4));
        $this->base = $root . '/private';
        $this->outside = $root . '/public';
        mkdir($this->base . '/12', 0777, true);
        mkdir($this->outside, 0777, true);
        file_put_contents($this->base . '/12/handout.pdf', 'pdf');
        file_put_contents($this->outside . '/secret.txt', 'secret');
    }

    protected function tearDown(): void
    {
        unlink($this->base . '/12/handout.pdf');
        unlink($this->outside . '/secret.txt');
        rmdir($this->base . '/12');
        rmdir($this->base);
        rmdir($this->outside);
        rmdir(dirname($this->base));
    }

    public function testResolvesFileInsideBase(): void
    {
        self::assertSame(
            realpath($this->base . '/12/handout.pdf'),
            DocumentStorage::resolve($this->base, '12/handout.pdf')
        );
    }

    public function testRejectsTraversalOutsideBase(): void
    {
        self::assertNull(DocumentStorage::resolve($this->base, '../public/secret.txt'));
        self::assertNull(DocumentStorage::resolve($this->base, '12/../../public/secret.txt'));
    }

    public function testRejectsMissingFilesAndDirectories(): void
    {
        self::assertNull(DocumentStorage::resolve($this->base, '12/missing.pdf'));
        self::assertNull(DocumentStorage::resolve($this->base, '12'));
    }
}
