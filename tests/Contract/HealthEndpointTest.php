<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Contract;

use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Mailadmin\Application\Maintenance\HealthComponent;
use Mailadmin\Application\Maintenance\HealthProbes;
use Mailadmin\Application\Maintenance\HealthReportCache;
use Mailadmin\Http\MiddlewareDispatcher;
use Mailadmin\Http\Routing\DocumentationEndpoint;
use Mailadmin\Infrastructure\Health\TimedProbe;
use Mailadmin\Infrastructure\Health\UncachedHealthReports;
use Mailadmin\Kernel\Bootstrap;
use Mailadmin\Runtime\Paths;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * `GET /health` end to end, through the container the application really boots,
 * with the answer checked against the contract.
 *
 * This is the shape every later package copies: drive the endpoint through the
 * assembled chain, then hand the response to the response validator. A test that
 * only asserted on a JSON field would keep passing after the contract changed
 * underneath it.
 *
 * The probes are replaced by ones the test controls (A6, U-121): what is
 * checked here is the endpoint, not whether a Cyrus happens to be reachable
 * from where the suite runs. The real probes are exercised by the F5 test.
 */
#[CoversNothing]
final class HealthEndpointTest extends ContractTestCase
{
    private ContainerInterface $container;
    private bool $imapDown = false;

    protected function setUp(): void
    {
        // Makes sure the artefacts exist before the container reads them.
        self::routeTable();

        $context = Bootstrap::prepare(Paths::detect());
        $context->cache->ensureUsable(mayCreate: true);

        $this->container = Bootstrap::container($context, [
            HealthProbes::class => new HealthProbes(
                new TimedProbe(HealthComponent::Database, static function (): void {
                }),
                new TimedProbe(HealthComponent::Imap, function (): void {
                    if ($this->imapDown) {
                        throw new RuntimeException('imap.internal.invalid refused the connection');
                    }
                }),
                new TimedProbe(HealthComponent::ManageSieve, static function (): void {
                }),
            ),
            HealthReportCache::class => new UncachedHealthReports(),
        ]);
    }

    public function testHealthAnswersOkAndMatchesTheContract(): void
    {
        $response = $this->get('/api/v1/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $this->assertMatchesContract('get', '/health', $response);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('ok', $body['status']);
        self::assertSame(
            [
                ['component' => 'database', 'status' => 'ok'],
                ['component' => 'imap', 'status' => 'ok'],
                ['component' => 'managesieve', 'status' => 'ok'],
            ],
            $body['checks'],
        );
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testAFailingComponentAnswers503AndStillMatchesTheContract(): void
    {
        $this->imapDown = true;

        $response = $this->get('/api/v1/health');

        self::assertSame(503, $response->getStatusCode());
        $this->assertMatchesContract('get', '/health', $response);

        $body = (string) $response->getBody();

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('degraded', $decoded['status']);
        self::assertSame(['status', 'checks'], array_keys($decoded));
        // No code, no host, no message: the reason is for `/maintenance/health`.
        self::assertStringNotContainsString('invalid', $body);
        self::assertStringNotContainsString('code', $body);
    }

    public function testHealthNeedsNoTokenBecauseTheContractSaysSo(): void
    {
        // No Authorization header anywhere in this test class.
        self::assertSame([], self::operations()['GET /health']['security']);
        self::assertSame(200, $this->get('/api/v1/health')->getStatusCode());
    }

    public function testHealthRevealsNothingAboutTheInstallation(): void
    {
        $body = (string) $this->get('/api/v1/health')->getBody();

        // Reachable without a token, so the representation stays thin: no
        // version, no host name, no path, no counts.
        self::assertSame(['status', 'checks'], array_keys(
            (array) json_decode($body, true, flags: JSON_THROW_ON_ERROR),
        ));
    }

    public function testEveryResponseCarriesARequestIdAndTheSecurityHeaders(): void
    {
        $response = $this->get('/api/v1/health');

        $policy = $response->getHeaderLine('Content-Security-Policy');

        self::assertNotSame('', $response->getHeaderLine('X-Request-Id'));
        self::assertStringContainsString("default-src 'self'", $policy);
        // U-35 allows inline styles for Material UI; scripts stay strict, and
        // that is the part E-15 leans on.
        self::assertStringContainsString("script-src 'self';", $policy);
        self::assertStringNotContainsString('unsafe-eval', $policy);
    }

    public function testTheContractIsServedUnderApiDocsOutsideProduction(): void
    {
        // U-9. There is no Redoc — see DocumentationEndpoint for why the CSP
        // rules it out — so the endpoint hands out the machine-readable
        // document instead.
        $index = $this->get(DocumentationEndpoint::INDEX_PATH);

        self::assertSame(302, $index->getStatusCode());
        self::assertSame(DocumentationEndpoint::SPECIFICATION_PATH, $index->getHeaderLine('Location'));

        $specification = $this->get(DocumentationEndpoint::SPECIFICATION_PATH);

        self::assertSame(200, $specification->getStatusCode());

        /** @var array<string, mixed> $document */
        $document = json_decode((string) $specification->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('3.0.3', $document['openapi']);
        self::assertArrayHasKey('/health', $document['paths']);
    }

    public function testAnUnknownPathIsAProblemDocument(): void
    {
        $response = $this->get('/api/v1/does-not-exist');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('request.not_found', $body['code']);
        self::assertArrayHasKey('request_id', $body);
    }

    private function assertMatchesContract(string $method, string $path, ResponseInterface $response): void
    {
        (new ValidatorBuilder())
            ->fromYamlFile(self::projectRoot() . '/var/cache/openapi.bundled.yaml')
            ->getResponseValidator()
            ->validate(new OperationAddress($path, $method), $response);

        $this->addToAssertionCount(1);
    }

    private function get(string $path): ResponseInterface
    {
        $chain = $this->container->get(MiddlewareDispatcher::class);
        self::assertInstanceOf(MiddlewareDispatcher::class, $chain);

        return $chain->handle((new Psr17Factory())->createServerRequest('GET', 'https://mail.example.test' . $path));
    }
}
