<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Http\Routing;

use Mailadmin\Http\Routing\BasePath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Small, but it sits on the input boundary: the validation middleware and the
 * dispatcher both strip the mount prefix, and if they disagreed the router
 * would match a request the validator had waved through.
 */
#[CoversClass(BasePath::class)]
final class BasePathTest extends TestCase
{
    #[DataProvider('paths')]
    public function testStripsTheMountPrefixAndNothingElse(string $path, string $basePath, string $expected): void
    {
        self::assertSame($expected, BasePath::strip($path, $basePath));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function paths(): iterable
    {
        yield 'no prefix configured' => ['/api/v1/health', '', '/api/v1/health'];
        yield 'prefix removed' => ['/mailadmin/api/v1/health', '/mailadmin', '/api/v1/health'];
        yield 'prefix alone becomes root' => ['/mailadmin', '/mailadmin', '/'];
        yield 'nested prefix' => ['/tools/mailadmin/api/v1/health', '/tools/mailadmin', '/api/v1/health'];
        yield 'root path' => ['/', '', '/'];

        // A path that only *starts* with the same characters is not a match —
        // otherwise `/mailadminister/...` would be silently rewritten.
        yield 'similar but different prefix' => ['/mailadminister/x', '/mailadmin', '/mailadminister/x'];
        yield 'prefix not present' => ['/api/v1/health', '/mailadmin', '/api/v1/health'];
    }
}
