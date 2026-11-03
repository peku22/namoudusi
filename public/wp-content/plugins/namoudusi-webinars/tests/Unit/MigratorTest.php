<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Tests\Unit;

use Namoudusi\Webinars\Database\Migration;
use Namoudusi\Webinars\Database\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    public function testMigrationVersionsAreSequentialFromOne(): void
    {
        foreach (Migrator::MIGRATIONS as $index => $class) {
            $migration = new $class();
            self::assertInstanceOf(Migration::class, $migration);
            self::assertSame($index + 1, $migration->version(), $class . ' has an unexpected version.');
        }
    }

    public function testTargetVersionMatchesLastMigration(): void
    {
        self::assertSame(count(Migrator::MIGRATIONS), Migrator::targetVersion());
    }
}
