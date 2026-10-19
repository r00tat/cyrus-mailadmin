<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Persistence\Guard;

use Mailadmin\Http\Middleware\RequestIdMiddleware;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\ErrorCode;
use Mailadmin\Infrastructure\Persistence\Guard\SchemaGuard;
use Mailadmin\Infrastructure\Persistence\Guard\SchemaVersionMiddleware;
use Mailadmin\Infrastructure\Persistence\Migration\MigrationLoader;
use Mailadmin\Infrastructure\Persistence\Migration\MigrationLock;
use Mailadmin\Infrastructure\Persistence\Migration\Migrator;
use Mailadmin\Infrastructure\Persistence\Migration\SchemaVersionTable;
use Mailadmin\Infrastructure\Persistence\OpenConnection;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(SchemaVersionMiddleware::class)]
#[RequiresPhpExtension('pdo_sqlite')]
final class SchemaVersionMiddlewareTest extends TestCase
{
    private PDO $pdo;

    private Connection $connection;

    private string $directory;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->connection = new Connection(new OpenConnection($this->pdo));
        $this->factory = new Psr17Factory();

        $this->directory = sys_get_temp_dir() . '/mailadmin-guard-mw-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        file_put_contents(
            $this->directory . '/0001_schema_version.sql',
            'CREATE TABLE IF NOT EXISTS mailadmin_schema_version (version INT);',
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testItRefusesWith503AndRetryAfter(): void
    {
        $response = $this->middleware()->process($this->request('/api/v1/domains'), $this->refusingHandler());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('30', $response->getHeaderLine('Retry-After'));
        self::assertSame(
            ProblemResponseFactory::CONTENT_TYPE,
            $response->getHeaderLine('Content-Type'),
        );
    }

    public function testTheBodyCarriesTheStableCodeAndNoInternalDetail(): void
    {
        $response = $this->middleware()->process($this->request('/api/v1/domains'), $this->refusingHandler());

        /** @var array<string, mixed> $problem */
        $problem = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(ErrorCode::SCHEMA_OUTDATED, $problem['code'] ?? null);
        self::assertSame(503, $problem['status'] ?? null);
        self::assertIsString($problem['detail'] ?? null);
        self::assertStringNotContainsString('bin/migrate.php', (string) $problem['detail']);
    }

    public function testTheProblemDocumentRepeatsTheRequestId(): void
    {
        $request = $this->request('/api/v1/domains')
            ->withAttribute(RequestIdMiddleware::ATTRIBUTE, 'abcdefgh1234');

        $response = $this->middleware()->process($request, $this->refusingHandler());

        /** @var array<string, mixed> $problem */
        $problem = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('abcdefgh1234', $problem['request_id'] ?? null);
    }

    /**
     * The endpoint whose job is to say what is wrong must not be the one that
     * stops answering when something is.
     */
    public function testHealthIsServedEvenWhileTheSchemaIsBehind(): void
    {
        $response = $this->middleware()->process($this->request('/api/v1/health'), $this->passingHandler());

        self::assertSame(204, $response->getStatusCode());
    }

    public function testARequestPassesThroughWhenTheSchemaIsCurrent(): void
    {
        $this->pdo->exec('CREATE TABLE mailadmin_schema_version (version INT PRIMARY KEY)');
        $this->pdo->exec('INSERT INTO mailadmin_schema_version (version) VALUES (1)');

        $response = $this->middleware()->process($this->request('/api/v1/domains'), $this->passingHandler());

        self::assertSame(204, $response->getStatusCode());
    }

    private function middleware(): SchemaVersionMiddleware
    {
        $versions = new SchemaVersionTable($this->connection);
        $loader = new MigrationLoader($this->directory);

        $guard = new SchemaGuard(
            versions: $versions,
            loader: $loader,
            migrator: new Migrator(
                $this->connection,
                $loader,
                $versions,
                MigrationLock::forDatabase($this->connection, null),
            ),
        );

        return new SchemaVersionMiddleware(
            $guard,
            new ProblemResponseFactory($this->factory, $this->factory),
        );
    }

    private function request(string $path): ServerRequestInterface
    {
        return new ServerRequest('GET', 'https://mail.example.test' . $path);
    }

    private function refusingHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                TestCase::fail('The handler must not run while the schema is behind.');
            }
        };
    }

    private function passingHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Psr17Factory())->createResponse(204);
            }
        };
    }
}
