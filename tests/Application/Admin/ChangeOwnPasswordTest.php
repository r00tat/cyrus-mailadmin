<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Application\Admin;

use Mailadmin\Application\Admin\AdminErrorCode;
use Mailadmin\Application\Admin\AdminOperationFailed;
use Mailadmin\Application\Admin\ChangeOwnPassword;
use Mailadmin\Domain\Model\AdminRole;
use Mailadmin\Domain\Model\PasswordFormat;
use Mailadmin\Domain\Password\InvalidPassword;
use Mailadmin\Infrastructure\Security\RefreshToken;
use Mailadmin\Infrastructure\Security\RefreshTokenRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `PUT /admins/me/password` — the one A4 operation a domain master may perform.
 */
#[CoversClass(ChangeOwnPassword::class)]
final class ChangeOwnPasswordTest extends AdminUseCaseTestCase
{
    public function testADomainMasterCanChangeTheirOwnPassword(): void
    {
        // The whole point of `profile.password.write`: webcyradm's
        // `changeadminpasswd` had no role check, and a domain master who has to
        // ask a superuser hands their account to somebody who then knows it.
        $this->givenAdmin('master', AdminRole::DomainMaster, ['example.test']);

        $this->changeOwnPassword()->handle($this->domainMaster('master'), self::PASSWORD, self::NEW_PASSWORD);

        self::assertTrue($this->hasher->verify(self::NEW_PASSWORD, (string) $this->store->storedHash('master')));
    }

    public function testTheWrongCurrentPasswordIsRefusedAndChangesNothing(): void
    {
        $this->givenAdmin('root');
        $before = $this->store->storedHash('root');

        try {
            $this->changeOwnPassword()->handle($this->superuser('root'), 'not-the-password', self::NEW_PASSWORD);
            self::fail('The wrong current password was accepted.');
        } catch (AdminOperationFailed $failure) {
            self::assertSame(AdminErrorCode::WRONG_CURRENT_PASSWORD, $failure->errorCode);
            self::assertSame(422, $failure->status);
        }

        self::assertSame($before, $this->store->storedHash('root'));
    }

    /**
     * An administrator who has not signed in since before E-13 still has a
     * legacy hash. They have to be able to change their password — that is how
     * the hash finally moves.
     *
     * The values are invented, produced by `crypt()` from the password below.
     */
    #[DataProvider('legacyHashes')]
    public function testALegacyHashCanBeVerifiedAndIsReplacedByArgonTwoId(string $storedHash): void
    {
        $this->givenAdmin('legacy', storedHash: $storedHash);

        $this->changeOwnPassword()->handle($this->superuser('legacy'), 'legacy-secret-2026', self::NEW_PASSWORD);

        self::assertSame(PasswordFormat::Argon2id, $this->store->find('legacy')?->passwordFormat);
        self::assertTrue($this->hasher->verify(self::NEW_PASSWORD, (string) $this->store->storedHash('legacy')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function legacyHashes(): iterable
    {
        yield 'DES-crypt' => [(string) crypt('legacy-secret-2026', 'ab')];
        yield 'md5crypt' => [(string) crypt('legacy-secret-2026', '$1$abcdefgh$')];
        yield 'sha512crypt' => [(string) crypt('legacy-secret-2026', '$6$abcdefghijklmnop$')];
        yield 'SHA1 hex' => [sha1('legacy-secret-2026')];
        yield 'plaintext' => ['legacy-secret-2026'];
    }

    public function testANewPasswordThatBreaksTheRulesIsRefused(): void
    {
        $this->givenAdmin('root');
        $before = $this->store->storedHash('root');

        $this->expectException(InvalidPassword::class);

        try {
            $this->changeOwnPassword()->handle($this->superuser('root'), self::PASSWORD, 'short');
        } finally {
            self::assertSame($before, $this->store->storedHash('root'));
        }
    }

    /**
     * Sessions opened with the old password should not outlive it. Singling out
     * "this one" would mean trusting the client's word about which one that is,
     * so all of them go — including the caller's.
     */
    public function testEveryRefreshFamilyOfThisAdministratorIsRevoked(): void
    {
        $this->givenAdmin('root');
        $this->givenAdmin('other');
        $this->givenRefreshToken('root', 'family-a');
        $this->givenRefreshToken('root', 'family-b');
        $this->givenRefreshToken('other', 'family-c');

        $this->changeOwnPassword()->handle($this->superuser('root'), self::PASSWORD, self::NEW_PASSWORD);

        foreach ($this->refreshTokens->all() as $record) {
            self::assertSame($record->subject === 'root', $record->isRevoked(), $record->family);
        }
    }

    public function testTheAuditEntryNamesTheActionAndCarriesNoState(): void
    {
        $this->givenAdmin('root');

        $this->changeOwnPassword()->handle($this->superuser('root'), self::PASSWORD, self::NEW_PASSWORD);

        $entries = $this->audit->all();

        self::assertCount(1, $entries);
        self::assertSame('admin.password.change', $entries[0]->action);
        self::assertSame('root', $entries[0]->objectId);
        self::assertNull($entries[0]->before);
        self::assertNull($entries[0]->after);

        $document = json_encode($entries[0]->toRepresentation(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(self::PASSWORD, $document);
        self::assertStringNotContainsString(self::NEW_PASSWORD, $document);
        self::assertStringNotContainsString('$argon2id$', $document);
    }

    private function givenRefreshToken(string $subject, string $family): void
    {
        $token = RefreshToken::generate();

        $this->refreshTokens->store(new RefreshTokenRecord(
            tokenHash: RefreshToken::hashOf($token->value),
            family: $family,
            subject: $subject,
            issuedAt: $this->clock->now(),
            expiresAt: $this->clock->now()->modify('+12 hours'),
        ));
    }
}
