<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Runtime;

use Mailadmin\Config\ConfigurationError;
use Mailadmin\Runtime\CacheDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheDirectory::class)]
final class CacheDirectoryTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/mailadmin-cache-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->base, 0o750, true));
    }

    protected function tearDown(): void
    {
        $this->removeRecursively($this->base);
    }

    public function testAnExistingWritableDirectoryIsAccepted(): void
    {
        $path = $this->base . '/cache';
        self::assertTrue(mkdir($path, 0o750));

        $cache = new CacheDirectory($path);
        $cache->ensureUsable(mayCreate: false);

        self::assertSame($path, $cache->path());
        self::assertDirectoryExists($path);
    }

    public function testItIsCreatedOnDemandWhenThatIsAllowed(): void
    {
        $path = $this->base . '/deeper/cache';

        (new CacheDirectory($path))->ensureUsable(mayCreate: true);

        self::assertDirectoryExists($path);
        self::assertDirectoryIsWritable($path);
    }

    /**
     * U-13: in production the artefacts under var/cache are build results shipped
     * by the deployment (U-8). Their absence means the deployment is incomplete, so
     * startup fails rather than the application quietly rebuilding under load.
     */
    public function testAMissingDirectoryIsAnErrorWhenCreatingIsNotAllowed(): void
    {
        $path = $this->base . '/cache';

        try {
            (new CacheDirectory($path))->ensureUsable(mayCreate: false);
            self::fail('Expected a ConfigurationError.');
        } catch (ConfigurationError $error) {
            self::assertStringContainsString($path, $error->getMessage());
            self::assertStringContainsString('composer build', $error->getMessage());
            self::assertStringContainsString('MAILADMIN_CACHE_DIR', $error->getMessage());
        }
    }

    public function testAFileWhereTheDirectoryShouldBeIsAnError(): void
    {
        $path = $this->base . '/cache';
        file_put_contents($path, 'not a directory');

        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('is not a directory');

        (new CacheDirectory($path))->ensureUsable(mayCreate: true);
    }

    public function testAnUnwritableDirectoryIsAnError(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignores directory permissions.');
        }

        $path = $this->base . '/cache';
        self::assertTrue(mkdir($path, 0o500));

        try {
            (new CacheDirectory($path))->ensureUsable(mayCreate: false);
            self::fail('Expected a ConfigurationError.');
        } catch (ConfigurationError $error) {
            self::assertStringContainsString('not writable', $error->getMessage());
        } finally {
            @chmod($path, 0o750);
        }
    }

    public function testCreationFailsInsideAnUnwritableParent(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignores directory permissions.');
        }

        $parent = $this->base . '/locked';
        self::assertTrue(mkdir($parent, 0o500));

        try {
            (new CacheDirectory($parent . '/cache'))->ensureUsable(mayCreate: true);
            self::fail('Expected a ConfigurationError.');
        } catch (ConfigurationError $error) {
            self::assertStringContainsString('could not be created', $error->getMessage());
        } finally {
            @chmod($parent, 0o750);
        }
    }

    public function testArtefactPathsAreBuiltBelowTheDirectory(): void
    {
        $cache = new CacheDirectory('/var/lib/mailadmin/cache');

        self::assertSame('/var/lib/mailadmin/cache', $cache->path());
        self::assertSame('/var/lib/mailadmin/cache/openapi.bundled.yaml', $cache->file('openapi.bundled.yaml'));
        self::assertSame('/var/lib/mailadmin/cache/routes.php', $cache->file('/routes.php'));
    }

    private function removeRecursively(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_dir($path)) {
            @chmod($path, 0o750);
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeRecursively($path . '/' . $entry);
                }
            }
            @rmdir($path);

            return;
        }

        @unlink($path);
    }
}
