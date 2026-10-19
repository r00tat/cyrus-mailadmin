<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\Kernel;

use Mailadmin\Config\Config;
use Mailadmin\Config\Environment;
use Mailadmin\Http\Middleware\RequestIdMiddleware;
use Mailadmin\Http\MiddlewareDispatcher;
use Mailadmin\Http\SecurityHeaders;
use Mailadmin\Kernel\Bootstrap;
use Mailadmin\Kernel\DefinitionLoader;
use Mailadmin\Runtime\CacheDirectory;
use Mailadmin\Runtime\Paths;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * The real startup path: environment, container definitions from `config/di/`,
 * autowiring, and a request through the chain the container assembles.
 *
 * Where {@see \Mailadmin\Tests\Integration\Http\ErrorHandlingTest} wires the chain
 * by hand to inspect its behaviour, this test checks that the wiring in
 * `config/di/core.php` actually produces it.
 */
#[CoversNothing]
final class ContainerBootTest extends TestCase
{
    private ContainerInterface $container;

    protected function setUp(): void
    {
        $context = Bootstrap::prepare(Paths::detect());
        $context->cache->ensureUsable(mayCreate: true);

        $this->container = Bootstrap::container($context);
    }

    public function testTheTestSuiteRunsInTheTestEnvironment(): void
    {
        $config = $this->container->get(Config::class);
        self::assertInstanceOf(Config::class, $config);

        self::assertSame(Environment::Test, $config->environment());
    }

    public function testTheRuntimeContextIsAvailableFromTheContainer(): void
    {
        self::assertInstanceOf(Paths::class, $this->container->get(Paths::class));
        self::assertInstanceOf(Config::class, $this->container->get(Config::class));
        self::assertInstanceOf(CacheDirectory::class, $this->container->get(CacheDirectory::class));
    }

    public function testTheDefinitionFilesLoadWithoutACollision(): void
    {
        $definitions = DefinitionLoader::load(Paths::detect()->containerDefinitions());

        self::assertArrayHasKey(MiddlewareDispatcher::class, $definitions);
        self::assertArrayHasKey(LoggerInterface::class, $definitions);
    }

    public function testTheLoggerIsResolvable(): void
    {
        self::assertInstanceOf(LoggerInterface::class, $this->container->get(LoggerInterface::class));
    }

    public function testOnePsr17FactoryServesEveryFactoryInterface(): void
    {
        $responses = $this->container->get(ResponseFactoryInterface::class);
        $streams = $this->container->get(StreamFactoryInterface::class);

        self::assertInstanceOf(Psr17Factory::class, $responses);
        self::assertSame($responses, $streams);
    }

    /**
     * F1's definition of done: a call against the front controller yields a JSON
     * response with `X-Request-Id`. F2 replaces the innermost handler with the
     * dispatcher compiled from the contract; the well-formed answer for a path
     * the contract does not describe is a 404.
     *
     * **The path has to be one that never gets a route.** It used to be
     * `/api/v1/domains`, which was unrouted when F1 was written and became a
     * real endpoint with A1 — at which point this test started asserting `404`
     * against an operation that answers `401` for want of a token. A name no
     * package will claim keeps the test about the chain rather than about
     * whichever endpoint happened not to exist yet.
     */
    public function testARequestThroughTheAssembledChainYieldsJsonWithARequestId(): void
    {
        $chain = $this->container->get(MiddlewareDispatcher::class);
        self::assertInstanceOf(MiddlewareDispatcher::class, $chain);

        $factory = new Psr17Factory();
        $response = $chain->handle(
            $factory->createServerRequest('GET', 'https://mail.example.test/api/v1/no-such-resource'),
        );

        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{32}$/',
            $response->getHeaderLine(RequestIdMiddleware::HEADER),
        );

        $decoded = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('code', $decoded);

        foreach (SecurityHeaders::HEADERS as $name => $value) {
            self::assertSame($value, $response->getHeaderLine($name));
        }
    }

    public function testTheChainIsRebuiltPerRequestOnlyAsFarAsItsPositionGoes(): void
    {
        $chain = $this->container->get(MiddlewareDispatcher::class);
        self::assertInstanceOf(MiddlewareDispatcher::class, $chain);

        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', 'https://mail.example.test/api/v1/no-such-resource');

        $first = $chain->handle($request);
        $second = $chain->handle($request);

        // Both responses are complete, i.e. the container's singleton dispatcher
        // did not resume halfway through the chain on the second call.
        self::assertSame(404, $first->getStatusCode());
        self::assertSame(404, $second->getStatusCode());
        self::assertNotSame(
            $first->getHeaderLine(RequestIdMiddleware::HEADER),
            $second->getHeaderLine(RequestIdMiddleware::HEADER),
        );
    }

    public function testTheTimezoneIsUtc(): void
    {
        // U-14. Bootstrap::prepare() set it; nothing may change it afterwards.
        self::assertSame('UTC', date_default_timezone_get());
    }
}
