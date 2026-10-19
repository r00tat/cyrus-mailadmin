<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Password;

use LogicException;
use Mailadmin\Domain\Model\PasswordFormat;

/**
 * A cleartext password that has passed {@see PasswordPolicy} (U-21).
 *
 * **There is no way to build one without the policy.** The constructor is
 * private and {@see create()} demands a `PasswordPolicy`, so "we forgot to
 * validate" is not a state this type can be in. It also carries its
 * {@see PasswordSubject}, which is what lets `PasswordHasher::hash()` pick the
 * target format without an algorithm argument anywhere in the call chain.
 *
 * **It is a cleartext secret, so it defends itself against leaking:**
 *
 *  - no `__toString()` — string interpolation is a fatal error, not a leak;
 *  - {@see __debugInfo()} redacts, so `var_dump()` and most debuggers show
 *    nothing;
 *  - {@see __sleep()} throws, so it cannot end up in a session, a cache entry
 *    or a serialised job payload.
 *
 * None of that helps against `var_export()` or Reflection. The rule stays the
 * rule: hand this object to the hasher and to nothing else, and never put it in
 * a log context — U-28 replaces objects by their class name, but only because
 * nobody unpacks them first.
 */
final readonly class Password
{
    /**
     * bcrypt hashes at most 72 bytes of input and ignores the rest.
     *
     * Not a style choice: past this point the stored hash no longer depends on
     * what was typed, so the user's secret would be weaker than the one they
     * chose. U-21 therefore rejects rather than truncates.
     */
    public const int BCRYPT_INPUT_LIMIT = 72;

    /**
     * An upper bound for every subject, including Argon2id.
     *
     * **Assumption beyond U-21** (which names no limit for `adminuser`): an
     * unbounded input is an easy way to make the server hash megabytes on an
     * unauthenticated endpoint. 4096 bytes is the value Django uses and is far
     * above any password a person types.
     */
    public const int ABSOLUTE_LIMIT = 4096;

    private function __construct(
        private string $value,
        private PasswordSubject $subject,
    ) {
    }

    /**
     * The only door. Validates, then wraps.
     *
     * @throws InvalidPassword when any rule of U-21 is broken
     */
    public static function create(
        string $plaintext,
        PasswordSubject $subject,
        PasswordPolicy $policy,
        ?PersonalTerms $terms = null,
    ): self {
        $policy->assertAcceptable($plaintext, $subject, $terms);

        return new self($plaintext, $subject);
    }

    /**
     * The second door, for a password that is **already in use**: it has just
     * been verified against the stored hash, and the only thing being done with
     * it is writing that same secret down in a better format (E-13).
     *
     * No policy, on purpose. The alternative was to run {@see create()} on the
     * rehash path and skip the rehash when today's rules reject the password —
     * which would leave exactly the weakest accounts sitting on a DES-crypt or
     * SHA1 hash forever, and would enforce a policy at the moment somebody is
     * signing in rather than at the moment somebody is choosing.
     *
     * **The caller must have verified the password first.** `SignIn` is the only
     * one that does, and it calls this after
     * {@see \Mailadmin\Infrastructure\Security\PasswordHasher::verify()} has
     * returned true. Anywhere else this is a way to store an unvalidated
     * password, which is what {@see create()} exists to prevent.
     */
    public static function alreadyInUse(string $plaintext, PasswordSubject $subject): self
    {
        return new self($plaintext, $subject);
    }

    /**
     * The cleartext. Called by {@see \Mailadmin\Infrastructure\Security\PasswordHasher}
     * and by nothing else.
     */
    public function reveal(): string
    {
        return $this->value;
    }

    public function subject(): PasswordSubject
    {
        return $this->subject;
    }

    /**
     * The format {@see \Mailadmin\Infrastructure\Security\PasswordHasher::hash()}
     * will write this password in.
     */
    public function targetFormat(): PasswordFormat
    {
        return $this->subject->targetFormat();
    }

    /** Length in characters, as U-21 counts it. */
    public function length(): int
    {
        return PasswordPolicy::characterLength($this->value);
    }

    public function lengthInBytes(): int
    {
        return strlen($this->value);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'value' => '[redacted]',
            'subject' => $this->subject->value,
        ];
    }

    /**
     * @return list<string>
     */
    public function __sleep(): array
    {
        throw new LogicException(
            'A Password must not be serialised: it holds a cleartext secret and would '
            . 'end up in whatever store the serialised graph is written to.',
        );
    }
}
