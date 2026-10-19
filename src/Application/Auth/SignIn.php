<?php

declare(strict_types=1);

namespace Mailadmin\Application\Auth;

use Mailadmin\Domain\Access\AdminDirectory;
use Mailadmin\Domain\Password\Password;
use Mailadmin\Domain\Password\PasswordSubject;
use Mailadmin\Infrastructure\Security\PasswordHasher;
use Mailadmin\Infrastructure\Security\PasswordHashingFailed;
use Mailadmin\Infrastructure\Security\RateLimiter;
use Mailadmin\Infrastructure\Security\TokenIssuer;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * `POST /auth/token`: credentials in, a token pair out (architecture 5.4).
 *
 * ## The order of operations is the security design
 *
 *  1. **Ask the rate limiter first.** Before any database read and before any
 *     hashing — the point of the limit is to stop paying for guesses.
 *  2. **Look the administrator up, then verify.** An unknown name still runs a
 *     dummy verification against a hash of the target cost, so that "no such
 *     user" and "wrong password" take comparable time. Without it the endpoint
 *     answers, by stopwatch, a question it refuses to answer in words.
 *  3. **Rehash on success, if the format has moved on** (E-13). This is the
 *     only moment the plaintext is known, so it is the only moment a DES-crypt
 *     or SHA1 hash in `adminuser` can become Argon2id. There is no migration
 *     campaign; there is this line.
 *  4. **Only then** clear the failure counter and mint the tokens.
 *
 * ## What is not here
 *
 * No check of what the administrator may *do* — that is S3. No end-user login:
 * `adminuser` is the only table consulted, and a mailbox owner has no row in it
 * (E-7).
 *
 * A rehash that fails is logged and swallowed. The password was correct; failing
 * the sign-in over a bookkeeping write would turn a bad Argon2id configuration
 * into a total outage, and the stored legacy hash still verifies fine.
 *
 * ## Both outcomes reach the audit trail
 *
 * A sign-in and a refused sign-in are the two things S4 records that are not
 * changes to an object. Every refusal is recorded with its **internal** reason —
 * rate limit, unknown name, wrong password, no usable scope — none of which the
 * response distinguishes. That asymmetry is the point: the endpoint must not say
 * which it was, and an operator reading the trail afterwards must be able to.
 */
final readonly class SignIn
{
    public function __construct(
        private AdminDirectory $directory,
        private PasswordHasher $hasher,
        private RateLimiter $rateLimiter,
        private TokenIssuer $issuer,
        private SessionFactory $sessions,
        private SessionAudit $audit,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws AuthenticationFailed
     */
    public function handle(
        string $username,
        #[SensitiveParameter] string $password,
        string $clientIp,
    ): IssuedSession {
        $decision = $this->rateLimiter->check($username, $clientIp);

        if (!$decision->allowed) {
            $this->logger->warning('A sign-in was refused by the rate limiter.', [
                'admin' => $username,
                'limited_by' => $decision->reason,
            ]);

            $this->audit->signInRefused($username, 'rate limited: ' . $decision->reason);

            throw AuthenticationFailed::rateLimited($decision->retryAfterSeconds, $decision->reason);
        }

        $this->rateLimiter->applyDelay($decision);

        $credentials = $this->directory->findCredentials($username);

        if ($credentials === null) {
            // The dummy verification costs what a real one costs. It is the
            // whole reason PasswordHasher has the method.
            $this->hasher->verifyDummy($password, PasswordSubject::AdminUser);
            $this->fail($username, $clientIp, 'unknown administrator');
        }

        if (!$this->hasher->verify($password, $credentials->passwordHash)) {
            $this->fail($username, $clientIp, 'wrong password');
        }

        $actor = $this->directory->findActor($credentials->username);

        if ($actor === null) {
            // The password was right, but this administrator cannot act — a
            // domain master whose last assignment was removed. Same answer as a
            // wrong password: the account's existence is not confirmed. The
            // reason is in the log, where an operator will find it.
            $this->fail($credentials->username, $clientIp, 'no usable domain scope');
        }

        $this->liftLegacyHash($credentials->username, $password, $credentials->passwordHash);

        $this->rateLimiter->recordSuccess($username);

        $this->logger->info('An administrator signed in.', [
            'admin' => $actor->username,
            'role' => $actor->role->value,
        ]);

        $this->audit->signedIn($actor);

        return $this->sessions->begin(
            $this->issuer->issue($actor->username, $actor->role),
            $actor->username,
        );
    }

    /**
     * @throws AuthenticationFailed
     *
     * @phpstan-return never
     */
    private function fail(string $username, string $clientIp, string $reason): never
    {
        $this->rateLimiter->recordFailure($username, $clientIp);

        // The reason is logged and never sent. `admin` is the name that was
        // *sent*, which may be an account that does not exist — that is what
        // makes the line useful when reading a burst of failures.
        $this->logger->notice('A sign-in attempt failed.', [
            'admin' => $username,
            'reason' => $reason,
        ]);

        $this->audit->signInRefused($username, $reason);

        throw AuthenticationFailed::invalidCredentials();
    }

    private function liftLegacyHash(
        string $username,
        #[SensitiveParameter] string $password,
        string $storedHash,
    ): void {
        if (!$this->hasher->needsRehash($storedHash, PasswordSubject::AdminUser)) {
            return;
        }

        try {
            $this->directory->replacePasswordHash(
                $username,
                $this->hasher->hash(Password::alreadyInUse($password, PasswordSubject::AdminUser)),
            );
        } catch (PasswordHashingFailed $error) {
            // Argon2id unavailable, or a broken PHP build. The sign-in stands.
            $this->logger->error('Could not rehash an administrator password.', [
                'admin' => $username,
                'reason' => $error->getMessage(),
            ]);
        }
    }
}
