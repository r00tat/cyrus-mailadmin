<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Runtime;

use Mailadmin\Runtime\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Paths::class)]
final class PathsTest extends TestCase
{
    public function testSegmentsAreJoinedWithoutDoubledSlashes(): void
    {
        $paths = new Paths('/srv/mailadmin');

        self::assertSame('/srv/mailadmin/config/di', $paths->join('config', 'di'));
        self::assertSame('/srv/mailadmin/config/di', $paths->join('/config/', '/di/'));
    }

    public function testTheDefaultsPointBelowTheProjectRoot(): void
    {
        $paths = new Paths('/srv/mailadmin');

        self::assertSame('/srv/mailadmin/config/di', $paths->containerDefinitions());
        self::assertSame('/srv/mailadmin/var/cache', $paths->defaultCacheDirectory());
    }

    /**
     * Only `public/` is inside the web root (architecture, section 3). Everything
     * this class resolves must sit beside it, not under it.
     */
    public function testDetectionFindsTheInstallationRootAndNotTheWebRoot(): void
    {
        $paths = Paths::detect();

        self::assertFileExists($paths->join('composer.json'));
        self::assertDirectoryExists($paths->join('public'));
        self::assertDirectoryExists($paths->containerDefinitions());
        self::assertStringEndsNotWith('/public', $paths->root);
    }
}
