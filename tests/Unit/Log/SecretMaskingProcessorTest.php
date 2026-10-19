<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Log;

use Mailadmin\Log\SecretMaskingProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecretMaskingProcessor::class)]
final class SecretMaskingProcessorTest extends TestCase
{
    private SecretMaskingProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new SecretMaskingProcessor();
    }

    /**
     * CLAUDE.md: passwords, hashes and tokens are never logged, not even
     * truncated. The list is matched by substring so that a package inventing
     * `smtp_relay_password` is covered without anyone remembering to add it.
     */
    #[DataProvider('secretKeys')]
    public function testASecretFieldIsRedacted(string $key): void
    {
        $record = $this->process([$key => 'super-secret-value']);

        self::assertSame(SecretMaskingProcessor::REDACTED, $record->context[$key]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function secretKeys(): iterable
    {
        yield 'password' => ['password'];
        yield 'new_password' => ['new_password'];
        yield 'current_password' => ['current_password'];
        yield 'db_password' => ['db_password'];
        yield 'passwd' => ['passwd'];
        yield 'passphrase' => ['passphrase'];
        yield 'password_hash' => ['password_hash'];
        yield 'hash' => ['hash'];
        yield 'crypt' => ['crypt'];
        yield 'secret' => ['secret'];
        yield 'client_secret' => ['client_secret'];
        yield 'token' => ['token'];
        yield 'access_token' => ['access_token'];
        yield 'refresh_token' => ['refresh_token'];
        yield 'authorization' => ['authorization'];
        yield 'cookie' => ['cookie'];
        yield 'set-cookie' => ['set-cookie'];
        yield 'api_key' => ['api_key'];
        yield 'apikey' => ['apiKey'];
        yield 'private_key' => ['private_key'];
        yield 'credentials' => ['credentials'];
        yield 'bearer' => ['bearer'];
        yield 'session_id' => ['session_id'];
        yield 'camelCase' => ['passwordHash'];
        yield 'upper case' => ['PASSWORD'];
        yield 'mixed case' => ['Refresh_Token'];
    }

    public function testTheValueDoesNotSurviveEvenPartially(): void
    {
        $record = $this->process(['password' => 'correct horse battery staple']);
        $encoded = json_encode($record->context, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('correct', $encoded);
        self::assertStringNotContainsString('staple', $encoded);
    }

    #[DataProvider('harmlessKeys')]
    public function testAHarmlessFieldIsLeftAlone(string $key, mixed $value): void
    {
        $record = $this->process([$key => $value]);

        self::assertSame($value, $record->context[$key]);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function harmlessKeys(): iterable
    {
        yield 'username' => ['username', 'admin'];
        yield 'domain' => ['domain', 'example.test'];
        yield 'quota' => ['quota', 1024];
        yield 'naming_scheme' => ['naming_scheme', 'dotted'];
        yield 'http_path' => ['http_path', '/api/v1/domains'];
    }

    /**
     * U-22 requires the hash format of an existing account to be *shown* rather
     * than hidden, and E-13 leaves legacy hashes in place deliberately. A log line
     * saying `[redacted]` instead of `des-crypt` would work against both. These
     * keys name a format, never a value.
     */
    #[DataProvider('metadataKeys')]
    public function testAKeyThatNamesAFormatRatherThanAValueIsKept(string $key, string $value): void
    {
        $record = $this->process([$key => $value]);

        self::assertSame($value, $record->context[$key]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function metadataKeys(): iterable
    {
        yield 'password_format' => ['password_format', 'bcrypt'];
        yield 'password_format legacy' => ['password_format', 'des-crypt'];
        yield 'hash_format' => ['hash_format', 'md5crypt'];
        yield 'hash_algorithm' => ['hash_algorithm', 'argon2id'];
        yield 'token_type' => ['token_type', 'Bearer'];
        yield 'upper case' => ['PASSWORD_FORMAT', 'bcrypt'];
    }

    /**
     * The allowlist is exact, not a prefix: a key that merely starts like one of
     * those names is still treated as a secret.
     */
    public function testTheAllowlistDoesNotOpenUpNeighbouringKeys(): void
    {
        $record = $this->process([
            'password_formatted' => 'hunter2',
            'old_password_format' => 'hunter2',
        ]);

        self::assertSame(SecretMaskingProcessor::REDACTED, $record->context['password_formatted']);
        self::assertSame(SecretMaskingProcessor::REDACTED, $record->context['old_password_format']);
    }

    public function testNestedSecretsAreRedactedToo(): void
    {
        $record = $this->process([
            'before' => [
                'username' => 'admin',
                'credentials' => ['password' => 'sekrit'],
            ],
            'after' => ['username' => 'admin', 'password' => 'sekrit2'],
        ]);

        /** @var array{username: string, credentials: string} $before */
        $before = $record->context['before'];
        self::assertSame('admin', $before['username']);
        self::assertSame(SecretMaskingProcessor::REDACTED, $before['credentials']);

        /** @var array{username: string, password: string} $after */
        $after = $record->context['after'];
        self::assertSame(SecretMaskingProcessor::REDACTED, $after['password']);
    }

    public function testExtraIsMaskedAsWellAsContext(): void
    {
        $record = $this->process([], ['token' => 'eyJhbGciOi...']);

        self::assertSame(SecretMaskingProcessor::REDACTED, $record->extra['token']);
    }

    /**
     * An object is replaced by its class name rather than traversed: Monolog would
     * otherwise serialise its properties, and a value object holding a hash would
     * leak through a field name the processor never sees.
     */
    public function testAnObjectIsReducedToItsClassName(): void
    {
        $record = $this->process(['actor' => new \stdClass()]);

        self::assertSame(\stdClass::class, $record->context['actor']);
    }

    public function testDeepNestingIsTruncatedRatherThanFollowed(): void
    {
        /** @var array<string, mixed> $nested */
        $nested = ['password' => 'innermost'];
        for ($i = 0; $i < 20; ++$i) {
            $nested = ['level' => $nested];
        }

        $record = $this->process($nested);
        $encoded = json_encode($record->context, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('innermost', $encoded);
        self::assertStringContainsString('_truncated', $encoded);
    }

    public function testAListKeepsItsKeys(): void
    {
        $record = $this->process(['domains' => ['a.test', 'b.test']]);

        self::assertSame(['a.test', 'b.test'], $record->context['domains']);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extra
     */
    private function process(array $context, array $extra = []): LogRecord
    {
        return ($this->processor)(new LogRecord(
            datetime: new \DateTimeImmutable('2026-07-26T12:00:00+00:00'),
            channel: 'mailadmin',
            level: Level::Info,
            message: 'test',
            context: $context,
            extra: $extra,
        ));
    }
}
