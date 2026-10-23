<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Contract;

use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Mailadmin\Domain\Access\AdminDirectory;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Password\Password;
use Mailadmin\Domain\Password\PasswordSubject;
use Mailadmin\Http\Handler\MailingLists\MailingListResponder;
use Mailadmin\Http\MiddlewareDispatcher;
use Mailadmin\Infrastructure\Persistence\InMemoryAdminDirectory;
use Mailadmin\Infrastructure\Persistence\InMemoryLoginAttemptLog;
use Mailadmin\Infrastructure\Persistence\InMemoryMailingListRepository;
use Mailadmin\Infrastructure\Persistence\InMemoryRefreshTokenStore;
use Mailadmin\Infrastructure\Persistence\InMemoryTokenDenylist;
use Mailadmin\Infrastructure\Persistence\MailingListRepository;
use Mailadmin\Infrastructure\Security\LoginAttemptLog;
use Mailadmin\Infrastructure\Security\PasswordHasher;
use Mailadmin\Infrastructure\Security\PasswordHashingOptions;
use Mailadmin\Infrastructure\Security\RefreshTokenStore;
use Mailadmin\Infrastructure\Security\TokenDenylist;
use Mailadmin\Kernel\Bootstrap;
use Mailadmin\Runtime\Paths;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The five `/lists` endpoints, driven through the container the application
 * really boots, with every answer checked against the contract.
 *
 * Doubled: the administrator directory and the three session stores, because
 * they would otherwise reach MySQL, and the mailing list repository for the same
 * reason. Everything else is the real thing — the real middleware chain, the
 * real authentication, the real authorisation, the real route table compiled
 * from `openapi/`.
 *
 * That split is what makes the `403` test worth having: it is produced by
 * `AuthorizationMiddleware` from the permission the contract declares, not by an
 * assertion about a policy class.
 */
#[CoversNothing]
final class MailingListEndpointTest extends ContractTestCase
{
    private const string PASSWORD = 'correct-horse-battery-staple';

    /** A separator this application would never write — the whole point. */
    private const string LEGACY_MEMBERS = 'anna@example.test;  bernd@example.test ;|/usr/bin/processor';

    private ContainerInterface $container;
    private InMemoryMailingListRepository $lists;

    protected function setUp(): void
    {
        self::routeTable();

        $hasher = new PasswordHasher(PasswordHashingOptions::of(
            bcryptCost: 4,
            argon2MemoryKib: 8192,
            argon2TimeCost: 1,
            argon2Threads: 1,
        ));
        $hash = $hasher->hash(Password::alreadyInUse(self::PASSWORD, PasswordSubject::AdminUser));

        $directory = new InMemoryAdminDirectory();
        $directory->given('root', $hash, AdminRole::Superuser);
        $directory->given('anna', $hash, AdminRole::DomainMaster, ['example.test']);

        $this->lists = new InMemoryMailingListRepository();
        $this->lists->given(
            MailingList::fromStoredValues(
                'legacy-list',
                'legacy',
                'legacy@example.test',
                self::LEGACY_MEMBERS,
            ),
            MailingList::fromStoredValues('team-example', 'team', 'team@example.test', 'anna@example.test'),
        );

        $context = Bootstrap::prepare(Paths::detect());
        $context->cache->ensureUsable(mayCreate: true);

        $this->container = Bootstrap::container($context, [
            AdminDirectory::class => $directory,
            RefreshTokenStore::class => new InMemoryRefreshTokenStore(),
            TokenDenylist::class => new InMemoryTokenDenylist(),
            LoginAttemptLog::class => new InMemoryLoginAttemptLog(),
            MailingListRepository::class => $this->lists,
        ]);
    }

    public function testTheCollectionMatchesTheContract(): void
    {
        $response = $this->send('GET', '/api/v1/lists?sort=name&order=asc', token: $this->tokenFor('root'));

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesContract('get', '/lists', $response);

        $body = self::bodyOf($response);

        self::assertSame(2, $body['meta']['total']);
        self::assertSame(['legacy-list', 'team-example'], array_map(
            static fn (mixed $item): mixed => is_array($item) ? $item['name'] : null,
            (array) $body['items'],
        ));
    }

    public function testASingleListCarriesItsMembersAsAnArrayAndFlagsTheOddEntry(): void
    {
        $response = $this->send('GET', '/api/v1/lists/legacy-list', token: $this->tokenFor('root'));

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesContract('get', '/lists/{listName}', $response);

        $body = self::bodyOf($response);

        self::assertSame(
            ['anna@example.test', 'bernd@example.test', '|/usr/bin/processor'],
            $body['members'],
        );
        self::assertSame(['|/usr/bin/processor'], $body['unusable_members']);
        self::assertTrue($body['address_usable']);
        self::assertMatchesRegularExpression('#^W/"[0-9a-f]{64}"$#', $response->getHeaderLine('ETag'));
    }

    public function testCreatingAListAnswersWithLocationAndTheEffectHeader(): void
    {
        $response = $this->send('POST', '/api/v1/lists', [
            'name' => 'new-list',
            'tag' => 'team',
            'address' => 'new-list@example.test',
            'members' => ['carla@example.test'],
        ], $this->tokenFor('root'));

        self::assertSame(201, $response->getStatusCode());
        $this->assertMatchesContract('post', '/lists', $response);

        self::assertSame('/api/v1/lists/new-list', $response->getHeaderLine('Location'));
        self::assertSame(
            MailingListResponder::EFFECT_PENDING,
            $response->getHeaderLine(MailingListResponder::EFFECT_HEADER),
            'A write has to say that it is not in effect until the configuration runs.',
        );
        self::assertSame('carla@example.test', $this->lists->storedMembers('new-list@example.test'));
    }

    public function testAnAddressThatIsTakenIsAConflict(): void
    {
        $response = $this->send('POST', '/api/v1/lists', [
            'name' => 'another-name',
            'address' => 'legacy@example.test',
        ], $this->tokenFor('root'));

        self::assertSame(409, $response->getStatusCode());
        $this->assertMatchesContract('post', '/lists', $response);
        self::assertSame('list.already_exists', self::bodyOf($response)['code']);
    }

    public function testAPipeEntryIsRefusedOverTheWire(): void
    {
        // The end-to-end form of the package's one security rule: this column is
        // rendered into an aliases file.
        $response = $this->send('POST', '/api/v1/lists', [
            'name' => 'evil-list',
            'address' => 'evil@example.test',
            'members' => ['|/bin/sh -c id'],
        ], $this->tokenFor('root'));

        self::assertSame(422, $response->getStatusCode());
        $this->assertMatchesContract('post', '/lists', $response);
        self::assertSame('list.invalid_member', self::bodyOf($response)['code']);
    }

    public function testATagChangeLeavesTheStoredMemberTextByteForByte(): void
    {
        $response = $this->send(
            'PATCH',
            '/api/v1/lists/legacy-list',
            ['tag' => 'crew'],
            $this->tokenFor('root'),
        );

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesContract('patch', '/lists/{listName}', $response);

        self::assertSame('crew', self::bodyOf($response)['tag']);
        self::assertSame(self::LEGACY_MEMBERS, $this->lists->storedMembers('legacy@example.test'));
        self::assertSame(
            MailingListResponder::EFFECT_PENDING,
            $response->getHeaderLine(MailingListResponder::EFFECT_HEADER),
        );
    }

    public function testAStaleIfMatchIsRefusedAndAFreshOneIsNot(): void
    {
        $token = $this->tokenFor('root');
        $stale = $this->send('GET', '/api/v1/lists/legacy-list', token: $token)->getHeaderLine('ETag');

        $this->send('PATCH', '/api/v1/lists/legacy-list', ['tag' => 'crew'], $token);

        $refused = $this->send(
            'PATCH',
            '/api/v1/lists/legacy-list',
            ['tag' => 'other'],
            $token,
            ['If-Match' => $stale],
        );

        self::assertSame(412, $refused->getStatusCode());
        $this->assertMatchesContract('patch', '/lists/{listName}', $refused);
        self::assertSame('request.precondition_failed', self::bodyOf($refused)['code']);

        $fresh = $this->send('GET', '/api/v1/lists/legacy-list', token: $token)->getHeaderLine('ETag');
        $accepted = $this->send(
            'PATCH',
            '/api/v1/lists/legacy-list',
            ['tag' => 'other'],
            $token,
            ['If-Match' => $fresh],
        );

        self::assertSame(200, $accepted->getStatusCode());
    }

    public function testDeletingAnswersTwoHundredAndFourWithTheEffectHeader(): void
    {
        $response = $this->send('DELETE', '/api/v1/lists/team-example', token: $this->tokenFor('root'));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame(
            MailingListResponder::EFFECT_PENDING,
            $response->getHeaderLine(MailingListResponder::EFFECT_HEADER),
            'The delete is the response with no body — and the one whose delay is least expected.',
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function everyOperation(): iterable
    {
        yield 'list' => ['GET', '/api/v1/lists', '/lists'];
        yield 'get' => ['GET', '/api/v1/lists/legacy-list', '/lists/{listName}'];
        yield 'create' => ['POST', '/api/v1/lists', '/lists'];
        yield 'update' => ['PATCH', '/api/v1/lists/legacy-list', '/lists/{listName}'];
        yield 'delete' => ['DELETE', '/api/v1/lists/legacy-list', '/lists/{listName}'];
    }

    #[DataProvider('everyOperation')]
    public function testADomainMasterIsForbidden(string $method, string $path, string $specPath): void
    {
        // U-58. The `403` comes out of AuthorizationMiddleware, from the
        // `x-permission` the contract declares — not from anything in A5.
        $response = $this->send($method, $path, self::bodyFor($method), $this->tokenFor('anna'));

        self::assertSame(403, $response->getStatusCode());
        $this->assertMatchesContract(strtolower($method), $specPath, $response);
        self::assertSame('request.forbidden', self::bodyOf($response)['code']);
    }

    #[DataProvider('everyOperation')]
    public function testNoneOfThemIsReachableWithoutAToken(string $method, string $path): void
    {
        self::assertSame(401, $this->send($method, $path, self::bodyFor($method))->getStatusCode());
    }

    public function testAListThatIsNotThereIsTheSameAnswerAsOneThatIsHidden(): void
    {
        $response = $this->send('GET', '/api/v1/lists/no-such-list', token: $this->tokenFor('root'));

        self::assertSame(404, $response->getStatusCode());
        $this->assertMatchesContract('get', '/lists/{listName}', $response);

        // Byte for byte the framework's own 404 — that is what makes a hidden
        // list indistinguishable from an absent one (CLAUDE.md, U-44).
        self::assertSame('request.not_found', self::bodyOf($response)['code']);
    }

    public function testAFilterTheSchemaDoesNotDescribeIsRejectedRatherThanIgnored(): void
    {
        $response = $this->send('GET', '/api/v1/lists?members=anna', token: $this->tokenFor('root'));

        self::assertSame(422, $response->getStatusCode());
        $this->assertMatchesContract('get', '/lists', $response);
        self::assertSame('request.validation_failed', self::bodyOf($response)['code']);
    }

    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private static function bodyFor(string $method): ?array
    {
        return match ($method) {
            'POST' => ['name' => 'another-list', 'address' => 'another@example.test'],
            'PATCH' => ['tag' => 'crew'],
            default => null,
        };
    }

    private function assertMatchesContract(string $method, string $path, ResponseInterface $response): void
    {
        (new ValidatorBuilder())
            ->fromYamlFile(self::projectRoot() . '/var/cache/openapi.bundled.yaml')
            ->getResponseValidator()
            ->validate(new OperationAddress($path, $method), $response);

        $this->addToAssertionCount(1);
    }

    private function tokenFor(string $username): string
    {
        $response = $this->send('POST', '/api/v1/auth/token', [
            'username' => $username,
            'password' => self::PASSWORD,
        ]);

        self::assertSame(200, $response->getStatusCode(), 'The fixture administrator could not sign in.');

        return (string) self::bodyOf($response)['access_token'];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     */
    private function send(
        string $method,
        string $path,
        ?array $body = null,
        ?string $token = null,
        array $headers = [],
    ): ResponseInterface {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest($method, 'https://mail.example.test' . $path);

        $query = parse_url($path, PHP_URL_QUERY);

        if (is_string($query)) {
            parse_str($query, $parameters);
            $request = $request->withQueryParams($parameters);
        }

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $chain = $this->container->get(MiddlewareDispatcher::class);
        self::assertInstanceOf(MiddlewareDispatcher::class, $chain);

        return $chain->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function bodyOf(ResponseInterface $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
