<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Security;

use Mailadmin\Domain\Model\PasswordFormat;
use Mailadmin\Domain\Password\PasswordSubject;
use Mailadmin\Infrastructure\Security\PasswordHasher;
use Mailadmin\Infrastructure\Security\PasswordHashingOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The dummy verification, checked rather than asserted in a comment.
 *
 * An unknown user name must cost the same as a known one. If it does not, the
 * response time answers "does this mailbox exist?", and mailbox enumeration is
 * where an attack on a mail server starts.
 *
 * Two independent checks, because each alone is weak:
 *
 *  1. **Structural, deterministic.** The dummy hash is in the same format and
 *     carries the same cost parameters as a real one, so verifying against it
 *     performs the identical computation. This is the check that catches a
 *     regression; it cannot be flaky.
 *  2. **Measured, with a wide margin.** The dummy path must not be short
 *     circuited. A one-sided bound — at least half the time of a real
 *     verification, median of several runs — because a machine under load can
 *     make any measurement slower but nothing can make a skipped bcrypt round
 *     take time.
 */
#[CoversClass(PasswordHasher::class)]
final class PasswordHasherTimingTest extends TestCase
{
    private const string SUBMITTED = 'Untypischer-Sonnenschirm-42';

    /**
     * The structural check: same format, same parameters, therefore same work.
     */
    #[DataProvider('subjects')]
    public function testTheDummyHashHasTheSameFormatAndCostAsARealOne(PasswordSubject $subject): void
    {
        $hasher = new PasswordHasher(PasswordHashingOptions::of(bcryptCost: 4, argon2MemoryKib: 8192, argon2TimeCost: 1));

        $dummy = self::dummyHashOf($hasher, $subject);

        self::assertSame($subject->targetFormat(), $hasher->detectFormat($dummy));

        // Same parameters as a hash this hasher would write today — which is
        // exactly what needsRehash() answers.
        self::assertFalse(
            $hasher->needsRehash($dummy, $subject),
            'The dummy hash differs in cost from a real one, so an unknown user name would be '
            . 'measurably faster or slower than a known one.',
        );
    }

    public function testTheDummyHashIsNotAConstantAcrossInstances(): void
    {
        $options = PasswordHashingOptions::of(bcryptCost: 4);

        self::assertNotSame(
            self::dummyHashOf(new PasswordHasher($options), PasswordSubject::AccountUser),
            self::dummyHashOf(new PasswordHasher($options), PasswordSubject::AccountUser),
        );
    }

    /**
     * The measured check. Bcrypt only: the cost is predictable and the run
     * stays under a second.
     */
    #[Group('timing')]
    public function testAnUnknownUserNameCostsAboutAsMuchAsAKnownOne(): void
    {
        // Cost 10 rather than 4: at cost 4 a bcrypt round is so cheap that the
        // measurement is mostly interpreter noise.
        $hasher = new PasswordHasher(PasswordHashingOptions::of(bcryptCost: 10));
        $storedHash = password_hash('a-different-secret', PASSWORD_BCRYPT, ['cost' => 10]);

        // Warm both paths: the dummy hash is built on first use.
        $hasher->verify(self::SUBMITTED, $storedHash);
        $hasher->verifyDummy(self::SUBMITTED, PasswordSubject::AccountUser);

        $known = self::medianSeconds(static fn () => $hasher->verify(self::SUBMITTED, $storedHash));
        $unknown = self::medianSeconds(
            static fn () => $hasher->verifyDummy(self::SUBMITTED, PasswordSubject::AccountUser),
        );

        self::assertGreaterThan(
            $known * 0.5,
            $unknown,
            sprintf(
                'The unknown-user path took %.1f ms against %.1f ms for a known user. It is being '
                . 'short circuited, and the response time tells an attacker which mailboxes exist.',
                $unknown * 1000,
                $known * 1000,
            ),
        );
    }

    /**
     * @return iterable<string, array{PasswordSubject}>
     */
    public static function subjects(): iterable
    {
        foreach (PasswordSubject::cases() as $subject) {
            if ($subject->targetFormat() === PasswordFormat::Argon2id
                && !in_array('argon2id', password_algos(), true)) {
                continue;
            }

            yield $subject->value => [$subject];
        }
    }

    /**
     * The dummy hash is private on purpose — no production caller has a reason
     * to see it. The test reaches for it because "the same work" is a claim
     * about an implementation detail.
     */
    private static function dummyHashOf(PasswordHasher $hasher, PasswordSubject $subject): string
    {
        $method = new ReflectionMethod($hasher, 'dummyHashFor');

        $hash = $method->invoke($hasher, $subject);

        self::assertIsString($hash);

        return $hash;
    }

    /**
     * @param callable(): mixed $call
     */
    private static function medianSeconds(callable $call, int $runs = 5): float
    {
        $timings = [];

        for ($run = 0; $run < $runs; ++$run) {
            $start = hrtime(true);
            $call();
            $timings[] = (hrtime(true) - $start) / 1_000_000_000;
        }

        sort($timings);

        return $timings[intdiv($runs, 2)];
    }
}
