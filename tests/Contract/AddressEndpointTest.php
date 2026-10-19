<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Contract;

use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Mailadmin\Application\Account\AccountStore;
use Mailadmin\Application\Address\AddressErrorCode;
use Mailadmin\Application\Address\AddressStore;
use Mailadmin\Application\Address\EntryKey;
use Mailadmin\Application\Address\ListDomainAddresses;
use Mailadmin\Application\Audit\AuditRequestContext;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Access\AdminDirectory;
use Mailadmin\Domain\Mailstore\SieveStore;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Domain\Password\Password;
use Mailadmin\Domain\Password\PasswordSubject;
use Mailadmin\Domain\Repair\RepairQueue;
use Mailadmin\Domain\Sieve\SieveRenderer;
use Mailadmin\Http\MiddlewareDispatcher;
use Mailadmin\Infrastructure\Mailstore\InMemorySieveStore;
use Mailadmin\Infrastructure\Mailstore\SieveScriptInstaller;
use Mailadmin\Infrastructure\Persistence\InMemoryAccountStore;
use Mailadmin\Infrastructure\Persistence\InMemoryAdminDirectory;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\InMemoryDomainStore;
use Mailadmin\Infrastructure\Persistence\InMemoryLoginAttemptLog;
use Mailadmin\Infrastructure\Persistence\InMemoryRefreshTokenStore;
use Mailadmin\Infrastructure\Persistence\InMemoryTokenDenylist;
use Mailadmin\Infrastructure\Persistence\InMemoryVirtualStore;
use Mailadmin\Infrastructure\Repair\InMemoryRepairQueue;
use Mailadmin\Infrastructure\Security\LoginAttemptLog;
use Mailadmin\Infrastructure\Security\PasswordHasher;
use Mailadmin\Infrastructure\Security\PasswordHashingOptions;
use Mailadmin\Infrastructure\Security\RefreshTokenStore;
use Mailadmin\Infrastructure\Security\TokenDenylist;
use Mailadmin\Kernel\Bootstrap;
use Mailadmin\Runtime\Paths;
use Mailadmin\Tests\Support\DomainFixture;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The fifteen A3 endpoints, driven through the container the application
 * really boots, with every answer checked against the contract.
 *
 * Only `virtual`, `accountuser`, `domain` and the Sieve service are doubled.
 * The authorisation middleware is the real one: a foreign object answers
 * exactly like a missing one, and no endpoint answers `403`.
 */
#[CoversNothing]
final class AddressEndpointTest extends ContractTestCase
{
    private const string ADMIN_PASSWORD = 'correct-horse-battery-staple';

    private ContainerInterface $container;
    private InMemoryVirtualStore $virtual;
    private InMemorySieveStore $sieve;
    private InMemoryAuditTrail $trail;

    protected function setUp(): void
    {
        self::routeTable();

        $hasher = new PasswordHasher(PasswordHashingOptions::of(
            bcryptCost: 4,
            argon2MemoryKib: 8192,
            argon2TimeCost: 1,
            argon2Threads: 1,
        ));
        $hash = $hasher->hash(Password::alreadyInUse(self::ADMIN_PASSWORD, PasswordSubject::AdminUser));

        $directory = new InMemoryAdminDirectory();
        $directory->given('root', $hash, AdminRole::Superuser, []);
        $directory->given('anna', $hash, AdminRole::DomainMaster, ['example.test']);

        $domains = new InMemoryDomainStore();
        $domains->given(DomainFixture::domain('example.test'));
        $domains->given(DomainFixture::domain('fremd.test', prefix: 'fremd.test'));

        $accounts = new InMemoryAccountStore();
        $this->virtual = new InMemoryVirtualStore();

        foreach (['info.example.test' => 'example.test', 'carl@example.test' => 'example.test', 'bert.fremd.test' => 'fremd.test'] as $username => $domain) {
            $record = $accounts->given($username, $domain);
            $this->virtual->givenAccount($username, $domain);
            $this->virtual->givenRow($record->requireIdentity()->address()->toString(), $username, $username);
        }

        $this->virtual->givenRow('team@example.test', 'info.example.test,extern@elsewhere.test', 'example.test');
        $this->virtual->givenRow('postmaster@example.test', 'info.example.test', 'info.example.test');
        $this->virtual->givenRow('team@fremd.test', 'bert.fremd.test', 'bert.fremd.test');
        $this->virtual->givenRow('kaputt@example.test', '', 'info.example.test');

        $this->sieve = new InMemorySieveStore();

        $auditContext = new AuditRequestContext();
        $this->trail = new InMemoryAuditTrail(context: $auditContext);

        $context = Bootstrap::prepare(Paths::detect());
        $context->cache->ensureUsable(mayCreate: true);

        $this->container = Bootstrap::container($context, [
            AdminDirectory::class => $directory,
            RefreshTokenStore::class => new InMemoryRefreshTokenStore(),
            TokenDenylist::class => new InMemoryTokenDenylist(),
            LoginAttemptLog::class => new InMemoryLoginAttemptLog(),
            DomainStore::class => $domains,
            AccountStore::class => $accounts,
            AddressStore::class => $this->virtual,
            SieveStore::class => $this->sieve,
            SieveScriptInstaller::class => new SieveScriptInstaller($this->sieve, new SieveRenderer()),
            RepairQueue::class => new InMemoryRepairQueue(),
            AuditWriter::class => $this->trail,
            AuditRequestContext::class => $auditContext,
            PasswordHasher::class => $hasher,
        ]);
    }

    // -------------------------------------------------------------- listing

    public function testTheListingShowsEveryRowUnknownOnesIncluded(): void
    {
        $response = $this->send('GET', '/api/v1/domains/example.test/addresses', null, $this->tokenFor('anna'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesContract('get', '/domains/{domain}/addresses', $response);

        $body = self::bodyOf($response);
        self::assertSame(5, $body['meta']['total']);
        $byAlias = array_column($body['items'], null, 'alias');
        self::assertSame('unknown', $byAlias['kaputt@example.test']['type']);
        self::assertSame('no_destination', $byAlias['kaputt@example.test']['unknown_reason']);
        self::assertTrue($byAlias['postmaster@example.test']['reserved']);
        self::assertNull($byAlias['team@example.test']['sender_account']);
    }

    public function testListingAForeignDomainAnswersExactlyLikeAMissingOne(): void
    {
        $token = $this->tokenFor('anna');

        $foreign = $this->send('GET', '/api/v1/domains/fremd.test/addresses', null, $token);
        $missing = $this->send('GET', '/api/v1/domains/gibtsnicht.test/addresses', null, $token);

        self::assertSame(404, $foreign->getStatusCode());
        self::assertSame(404, $missing->getStatusCode());
        $this->assertMatchesContract('get', '/domains/{domain}/addresses', $foreign);
        self::assertSame(self::withoutPerRequestFields(self::bodyOf($foreign)), self::withoutPerRequestFields(self::bodyOf($missing)));
    }

    public function testAnUndeclaredSortIsReported(): void
    {
        $response = $this->send('GET', '/api/v1/domains/example.test/addresses?sort=dest', null, $this->tokenFor('root'));

        self::assertSame(422, $response->getStatusCode());
        $this->assertMatchesContract('get', '/domains/{domain}/addresses', $response);
    }

    public function testTheSortEnumerationIsTheUseCasesAllowlist(): void
    {
        /** @var array<string, mixed> $parameter */
        $parameter = self::contract()['components']['parameters']['AddressSort'];

        self::assertSame(ListDomainAddresses::SORTS, $parameter['schema']['enum']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function everyOperation(): iterable
    {
        yield 'listDomainAddresses' => ['GET', '/api/v1/domains/example.test/addresses', null];
        yield 'deleteDomainAddress' => ['DELETE', '/api/v1/domains/example.test/addresses/' . str_repeat('a', 32), null];
        yield 'createAlias' => ['POST', '/api/v1/domains/example.test/aliases', ['local_part' => 'x', 'destinations' => ['a@b.test']]];
        yield 'getAlias' => ['GET', '/api/v1/aliases/team%40example.test', null];
        yield 'updateAlias' => ['PATCH', '/api/v1/aliases/team%40example.test', ['enabled' => false]];
        yield 'deleteAlias' => ['DELETE', '/api/v1/aliases/team%40example.test', null];
        yield 'getCatchall' => ['GET', '/api/v1/domains/example.test/catchall', null];
        yield 'setCatchall' => ['PUT', '/api/v1/domains/example.test/catchall', ['destinations' => ['a@b.test']]];
        yield 'deleteCatchall' => ['DELETE', '/api/v1/domains/example.test/catchall', null];
        yield 'getForwards' => ['GET', '/api/v1/accounts/info.example.test/forwards', null];
        yield 'setForwards' => ['PUT', '/api/v1/accounts/info.example.test/forwards', ['destinations' => ['a@b.test']]];
        yield 'deleteForwards' => ['DELETE', '/api/v1/accounts/info.example.test/forwards', null];
        yield 'getVacation' => ['GET', '/api/v1/accounts/info.example.test/vacation', null];
        yield 'setVacation' => ['PUT', '/api/v1/accounts/info.example.test/vacation', ['message' => 'Weg.']];
        yield 'deleteVacation' => ['DELETE', '/api/v1/accounts/info.example.test/vacation', null];
    }

    /**
     * A body that passes schema validation, so that the answer is about the
     * token and nothing else.
     *
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('everyOperation')]
    public function testEveryOperationNeedsAToken(string $method, string $path, ?array $body): void
    {
        $response = $this->send($method, $path, $body, null);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], $this->virtual->operations());
    }

    /**
     * @return iterable<string, array{string, string, string, array<string, mixed>|null}>
     */
    public static function foreignAndMissing(): iterable
    {
        yield 'getAlias' => ['GET', '/api/v1/aliases/team%40fremd.test', '/api/v1/aliases/nix%40example.test', null];
        yield 'updateAlias' => ['PATCH', '/api/v1/aliases/team%40fremd.test', '/api/v1/aliases/nix%40example.test', ['enabled' => false]];
        yield 'deleteAlias' => ['DELETE', '/api/v1/aliases/team%40fremd.test', '/api/v1/aliases/nix%40example.test', null];
        yield 'createAlias' => ['POST', '/api/v1/domains/fremd.test/aliases', '/api/v1/domains/gibtsnicht.test/aliases', ['local_part' => 'x', 'destinations' => ['a@b.test']]];
        yield 'getCatchall' => ['GET', '/api/v1/domains/fremd.test/catchall', '/api/v1/domains/gibtsnicht.test/catchall', null];
        yield 'setCatchall' => ['PUT', '/api/v1/domains/fremd.test/catchall', '/api/v1/domains/gibtsnicht.test/catchall', ['destinations' => ['a@b.test']]];
        yield 'deleteCatchall' => ['DELETE', '/api/v1/domains/fremd.test/catchall', '/api/v1/domains/gibtsnicht.test/catchall', null];
        yield 'getForwards' => ['GET', '/api/v1/accounts/bert.fremd.test/forwards', '/api/v1/accounts/nix.example.test/forwards', null];
        yield 'setForwards' => ['PUT', '/api/v1/accounts/bert.fremd.test/forwards', '/api/v1/accounts/nix.example.test/forwards', ['destinations' => ['a@b.test']]];
        yield 'deleteForwards' => ['DELETE', '/api/v1/accounts/bert.fremd.test/forwards', '/api/v1/accounts/nix.example.test/forwards', null];
        yield 'getVacation' => ['GET', '/api/v1/accounts/bert.fremd.test/vacation', '/api/v1/accounts/nix.example.test/vacation', null];
        yield 'setVacation' => ['PUT', '/api/v1/accounts/bert.fremd.test/vacation', '/api/v1/accounts/nix.example.test/vacation', ['message' => 'Weg.']];
        yield 'deleteVacation' => ['DELETE', '/api/v1/accounts/bert.fremd.test/vacation', '/api/v1/accounts/nix.example.test/vacation', null];
        yield 'deleteDomainAddress' => ['DELETE', '/api/v1/domains/fremd.test/addresses/' . str_repeat('a', 32), '/api/v1/domains/example.test/addresses/' . str_repeat('a', 32), null];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('foreignAndMissing')]
    public function testAForeignObjectAnswersExactlyLikeAMissingOne(string $method, string $foreignPath, string $missingPath, ?array $body): void
    {
        $token = $this->tokenFor('anna');

        $foreign = $this->send($method, $foreignPath, $body, $token);
        $missing = $this->send($method, $missingPath, $body, $token);

        self::assertSame(404, $foreign->getStatusCode(), (string) $foreign->getBody());
        self::assertSame(404, $missing->getStatusCode(), (string) $missing->getBody());
        self::assertSame(self::withoutPerRequestFields(self::bodyOf($foreign)), self::withoutPerRequestFields(self::bodyOf($missing)));
        self::assertSame([], $this->virtual->operations());
        self::assertSame([], $this->sieve->operations());
    }

    // ------------------------------------------------------- a single entry

    public function testRemovingAnUnknownRowByItsKey(): void
    {
        $key = $this->keyOf('kaputt@example.test');

        $response = $this->send('DELETE', '/api/v1/domains/example.test/addresses/' . $key, null, $this->tokenFor('anna'));

        self::assertSame(204, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesContract('delete', '/domains/{domain}/addresses/{entry}', $response);
        self::assertSame([], $this->virtual->rowsWithAlias('kaputt@example.test'));
        self::assertSame('address.delete', $this->trail->last()?->action);
    }

    public function testAMailboxAddressIsNotRemovedAsAnEntry(): void
    {
        $response = $this->send('DELETE', '/api/v1/domains/example.test/addresses/' . $this->keyOf('info@example.test'), null, $this->tokenFor('anna'));

        self::assertSame(409, $response->getStatusCode());
        $this->assertMatchesContract('delete', '/domains/{domain}/addresses/{entry}', $response);
        self::assertSame(AddressErrorCode::NOT_EDITABLE, self::bodyOf($response)['code']);
    }

    // --------------------------------------------------------------- aliases

    public function testCreatingAnAliasAnswersWithLocationAndEtag(): void
    {
        $response = $this->send('POST', '/api/v1/domains/example.test/aliases', [
            'local_part' => 'Verkauf',
            'destinations' => ['INFO.example.test'],
        ], $this->tokenFor('anna'));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesContract('post', '/domains/{domain}/aliases', $response);
        self::assertSame('/api/v1/aliases/verkauf%40example.test', $response->getHeaderLine('Location'));
        self::assertNotSame('', $response->getHeaderLine('ETag'));

        $body = self::bodyOf($response);
        self::assertSame('verkauf@example.test', $body['address']);
        self::assertSame(['info.example.test'], $body['destinations']);
        self::assertSame('info.example.test', $body['sender_account']);
        self::assertSame('alias.create', $this->trail->last()?->action);
    }

    public function testATakenAddressIsAConflict(): void
    {
        $response = $this->send('POST', '/api/v1/domains/example.test/aliases', [
            'local_part' => 'info',
            'destinations' => ['extern@elsewhere.test'],
        ], $this->tokenFor('anna'));

        self::assertSame(409, $response->getStatusCode());
        $this->assertMatchesContract('post', '/domains/{domain}/aliases', $response);
        self::assertSame(AddressErrorCode::TAKEN, self::bodyOf($response)['code']);
    }

    public function testAnUnusableDestinationIsAValidationError(): void
    {
        $response = $this->send('POST', '/api/v1/domains/example.test/aliases', [
            'local_part' => 'neu',
            'destinations' => ['niemand'],
        ], $this->tokenFor('anna'));

        self::assertSame(422, $response->getStatusCode());
        $this->assertMatchesContract('post', '/domains/{domain}/aliases', $response);
        self::assertSame('address.invalid_destination', self::bodyOf($response)['code']);
    }

    public function testAnAliasIsReadChangedAndRemoved(): void
    {
        $token = $this->tokenFor('anna');

        $read = $this->send('GET', '/api/v1/aliases/team%40example.test', null, $token);
        self::assertSame(200, $read->getStatusCode(), (string) $read->getBody());
        $this->assertMatchesContract('get', '/aliases/{alias}', $read);
        self::assertTrue(self::bodyOf($read)['editable']);

        $stale = $this->send('PATCH', '/api/v1/aliases/team%40example.test', ['enabled' => false], $token, 'W/"stale"');
        self::assertSame(412, $stale->getStatusCode());
        $this->assertMatchesContract('patch', '/aliases/{alias}', $stale);

        $changed = $this->send('PATCH', '/api/v1/aliases/team%40example.test', [
            'destinations' => ['carl@example.test'],
        ], $token, $read->getHeaderLine('ETag'));
        self::assertSame(200, $changed->getStatusCode(), (string) $changed->getBody());
        $this->assertMatchesContract('patch', '/aliases/{alias}', $changed);
        self::assertSame(['carl@example.test'], self::bodyOf($changed)['destinations']);

        $deleted = $this->send('DELETE', '/api/v1/aliases/team%40example.test', null, $token, $changed->getHeaderLine('ETag'));
        self::assertSame(204, $deleted->getStatusCode());
        $this->assertMatchesContract('delete', '/aliases/{alias}', $deleted);
        self::assertSame([], $this->virtual->rowsWithAlias('team@example.test'));
    }

    public function testAnEmptyPatchIsAValidationError(): void
    {
        $response = $this->send('PATCH', '/api/v1/aliases/team%40example.test', [], $this->tokenFor('anna'));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testRemovingPostmasterNeedsConfirmation(): void
    {
        $token = $this->tokenFor('anna');

        $refused = $this->send('DELETE', '/api/v1/aliases/postmaster%40example.test', null, $token);
        self::assertSame(409, $refused->getStatusCode());
        $this->assertMatchesContract('delete', '/aliases/{alias}', $refused);
        self::assertSame(AddressErrorCode::RESERVED, self::bodyOf($refused)['code']);

        $confirmed = $this->send('DELETE', '/api/v1/aliases/postmaster%40example.test?confirm_reserved=true', null, $token);
        self::assertSame(204, $confirmed->getStatusCode(), (string) $confirmed->getBody());
    }

    // -------------------------------------------------------------- catchall

    public function testTheCatchallIsSetReadAndRemoved(): void
    {
        $token = $this->tokenFor('anna');

        $empty = $this->send('GET', '/api/v1/domains/example.test/catchall', null, $token);
        self::assertSame(200, $empty->getStatusCode(), (string) $empty->getBody());
        $this->assertMatchesContract('get', '/domains/{domain}/catchall', $empty);
        self::assertFalse(self::bodyOf($empty)['configured']);

        $set = $this->send('PUT', '/api/v1/domains/example.test/catchall', ['destinations' => ['info.example.test']], $token);
        self::assertSame(200, $set->getStatusCode(), (string) $set->getBody());
        $this->assertMatchesContract('put', '/domains/{domain}/catchall', $set);
        self::assertTrue(self::bodyOf($set)['configured']);
        self::assertNull(self::bodyOf($set)['sender_account']);

        $loop = $this->send('PUT', '/api/v1/domains/example.test/catchall', ['destinations' => ['nix@example.test']], $token);
        self::assertSame(422, $loop->getStatusCode());
        $this->assertMatchesContract('put', '/domains/{domain}/catchall', $loop);

        $deleted = $this->send('DELETE', '/api/v1/domains/example.test/catchall', null, $token, $set->getHeaderLine('ETag'));
        self::assertSame(204, $deleted->getStatusCode(), (string) $deleted->getBody());
        $this->assertMatchesContract('delete', '/domains/{domain}/catchall', $deleted);
    }

    // -------------------------------------------------------------- forwards

    public function testForwardsAreSetReadAndRemoved(): void
    {
        $token = $this->tokenFor('anna');

        $set = $this->send('PUT', '/api/v1/accounts/info.example.test/forwards', [
            'destinations' => ['extern@elsewhere.test'],
            'keep_copy' => false,
        ], $token);
        self::assertSame(200, $set->getStatusCode(), (string) $set->getBody());
        $this->assertMatchesContract('put', '/accounts/{account}/forwards', $set);
        self::assertFalse(self::bodyOf($set)['keep_copy']);

        $read = $this->send('GET', '/api/v1/accounts/info.example.test/forwards', null, $token);
        self::assertSame(200, $read->getStatusCode());
        $this->assertMatchesContract('get', '/accounts/{account}/forwards', $read);
        self::assertSame(['extern@elsewhere.test'], self::bodyOf($read)['destinations']);

        $deleted = $this->send('DELETE', '/api/v1/accounts/info.example.test/forwards', null, $token, $read->getHeaderLine('ETag'));
        self::assertSame(204, $deleted->getStatusCode());
        $this->assertMatchesContract('delete', '/accounts/{account}/forwards', $deleted);
    }

    public function testAnFqunAccountCannotDropItsCopy(): void
    {
        $response = $this->send('PUT', '/api/v1/accounts/carl%40example.test/forwards', [
            'destinations' => ['extern@elsewhere.test'],
            'keep_copy' => false,
        ], $this->tokenFor('anna'));

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesContract('put', '/accounts/{account}/forwards', $response);
        self::assertSame(AddressErrorCode::FORWARD_COPY_REQUIRED, self::bodyOf($response)['code']);
    }

    // -------------------------------------------------------------- vacation

    public function testTheVacationNoticeIsSetReadAndRemoved(): void
    {
        $token = $this->tokenFor('anna');

        $none = $this->send('GET', '/api/v1/accounts/info.example.test/vacation', null, $token);
        self::assertSame(200, $none->getStatusCode(), (string) $none->getBody());
        $this->assertMatchesContract('get', '/accounts/{account}/vacation', $none);
        self::assertSame('none', self::bodyOf($none)['state']);

        $set = $this->send('PUT', '/api/v1/accounts/info.example.test/vacation', [
            'message' => 'Bis Montag nicht im Haus.',
            'interval_days' => 3,
        ], $token, $none->getHeaderLine('ETag'));
        self::assertSame(200, $set->getStatusCode(), (string) $set->getBody());
        $this->assertMatchesContract('put', '/accounts/{account}/vacation', $set);
        self::assertSame('active', self::bodyOf($set)['state']);
        self::assertSame(['info@example.test'], self::bodyOf($set)['addresses']);
        self::assertNull(self::bodyOf($set)['script']);

        $deleted = $this->send('DELETE', '/api/v1/accounts/info.example.test/vacation', null, $token);
        self::assertSame(204, $deleted->getStatusCode(), (string) $deleted->getBody());
        $this->assertMatchesContract('delete', '/accounts/{account}/vacation', $deleted);
    }

    public function testAForeignScriptIsShownAndOnlyReplacedWithTakeover(): void
    {
        $token = $this->tokenFor('anna');
        $this->sieve->givenForeignScript(self::identity('info.example.test'), "keep;\n", 'roundcube');

        $read = $this->send('GET', '/api/v1/accounts/info.example.test/vacation', null, $token);
        self::assertSame('foreign', self::bodyOf($read)['state']);
        self::assertSame("keep;\n", self::bodyOf($read)['script']);
        $this->assertMatchesContract('get', '/accounts/{account}/vacation', $read);

        $refused = $this->send('PUT', '/api/v1/accounts/info.example.test/vacation', ['message' => 'Weg.'], $token);
        self::assertSame(409, $refused->getStatusCode());
        $this->assertMatchesContract('put', '/accounts/{account}/vacation', $refused);
        self::assertSame(AddressErrorCode::VACATION_FOREIGN_SCRIPT, self::bodyOf($refused)['code']);

        $refusedDelete = $this->send('DELETE', '/api/v1/accounts/info.example.test/vacation', null, $token);
        self::assertSame(409, $refusedDelete->getStatusCode());

        $taken = $this->send('PUT', '/api/v1/accounts/info.example.test/vacation', ['message' => 'Weg.', 'take_over' => true], $token);
        self::assertSame(200, $taken->getStatusCode(), (string) $taken->getBody());
        self::assertSame('active', self::bodyOf($taken)['state']);
    }

    public function testAnOverlongNoticeIsAValidationError(): void
    {
        $response = $this->send('PUT', '/api/v1/accounts/info.example.test/vacation', [
            'message' => str_repeat('x', 4001),
        ], $this->tokenFor('anna'));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->sieve->operations());
    }

    // ----------------------------------------------------------------- codes

    public function testEveryErrorCodeA3RaisesIsInTheContractEnumeration(): void
    {
        /** @var list<string> $enum */
        $enum = self::contract()['components']['schemas']['ErrorCode']['enum'];

        foreach (AddressErrorCode::all() as $code) {
            self::assertContains($code, $enum, sprintf('AddressErrorCode "%s" is not in the contract.', $code));
        }
    }

    public function testNoA3OperationDeclaresA403(): void
    {
        foreach (self::contract()['paths'] as $path => $operations) {
            if (!is_string($path) || !preg_match('#/(addresses|aliases|catchall|forwards|vacation)#', $path)) {
                continue;
            }

            foreach ($operations as $method => $operation) {
                if (is_array($operation) && isset($operation['responses'])) {
                    self::assertArrayNotHasKey('403', $operation['responses'], $method . ' ' . $path);
                }
            }
        }
    }

    // -----------------------------------------------------------------

    private function keyOf(string $alias): string
    {
        $rows = $this->virtual->rowsWithAlias($alias);
        self::assertCount(1, $rows);

        return EntryKey::of($rows[0]);
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
            'password' => self::ADMIN_PASSWORD,
        ], null);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (string) self::bodyOf($response)['access_token'];
    }

    private static function identity(string $storedUsername, string $domain = 'example.test'): MailboxIdentity
    {
        return MailboxIdentity::fromStoredUsername($storedUsername, DomainName::fromStoredValue($domain));
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body, ?string $token, ?string $ifMatch = null): ResponseInterface
    {
        $factory = new Psr17Factory();
        $uri = $factory->createUri('https://mail.example.test' . $path);
        $request = $factory->createServerRequest($method, $uri);

        parse_str($uri->getQuery(), $query);
        $request = $request->withQueryParams($query);

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode((object) $body, JSON_THROW_ON_ERROR)));
        }

        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        if ($ifMatch !== null) {
            $request = $request->withHeader('If-Match', $ifMatch);
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

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private static function withoutPerRequestFields(array $document): array
    {
        unset($document['instance'], $document['request_id']);

        return $document;
    }
}
