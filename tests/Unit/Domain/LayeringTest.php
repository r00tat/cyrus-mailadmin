<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The domain model does not import from the layers above it (architecture 3).
 *
 * Written down when S3 added `Domain/Policy/`, because that is the namespace
 * most likely to reach for the wrong thing: a policy wants a status code, and a
 * status code lives in `Http`. It must not — the policies have to be usable from
 * a command-line tool, and a domain layer that knows about HTTP is one that
 * cannot be.
 *
 * The ports are the deliberate exception in the other direction: `Domain\Access\AdminDirectory`
 * and the mailstore interfaces (U-37) are *declared* here and *implemented*
 * outside, which is what an inward-pointing dependency looks like.
 */
#[CoversNothing]
final class LayeringTest extends TestCase
{
    /** Namespaces a file under `src/Domain` may not import from. */
    private const array FORBIDDEN = ['Http', 'Application', 'Infrastructure', 'Kernel', 'Config', 'Log', 'Runtime'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function domainFiles(): iterable
    {
        $root = dirname(__DIR__, 3) . '/src/Domain';

        /** @var iterable<\SplFileInfo> $files */
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $path = (string) $file->getPathname();

                yield substr($path, strlen($root) + 1) => [$path];
            }
        }
    }

    #[DataProvider('domainFiles')]
    public function testADomainFileImportsNothingFromAnOuterLayer(string $path): void
    {
        $source = file_get_contents($path);

        self::assertIsString($source);

        foreach (self::FORBIDDEN as $namespace) {
            self::assertDoesNotMatchRegularExpression(
                '/^use\s+Mailadmin\\\\' . $namespace . '\\\\/m',
                $source,
                sprintf(
                    '%s imports from Mailadmin\\%s. The domain model points inward only (architecture 3); '
                    . 'if the outer layer has something the model needs, the model declares a port for it.',
                    basename($path),
                    $namespace,
                ),
            );
        }
    }

    public function testTheProviderActuallyFoundSomething(): void
    {
        // A data provider that silently yields nothing is a test that passes by
        // testing nothing — the failure mode this whole file exists to prevent.
        self::assertGreaterThan(20, iterator_count(self::domainFiles()));
    }
}
