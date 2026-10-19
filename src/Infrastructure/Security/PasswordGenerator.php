<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Security;

use Mailadmin\Domain\Password\InvalidPassword;
use Mailadmin\Domain\Password\Password;
use Mailadmin\Domain\Password\PasswordPolicy;
use Mailadmin\Domain\Password\PasswordSubject;
use Mailadmin\Domain\Password\PersonalTerms;
use RuntimeException;

/**
 * Suggests a password for an account an administrator is creating.
 *
 * **Readable over the telephone, not maximally dense** — S1's open question 4,
 * answered by what happens to the password afterwards: an administrator
 * creates a mailbox and has to pass the password to a person, usually by
 * dictating or copying it into a letter. A string that cannot be read aloud
 * without ambiguity gets mistyped, and a mistyped password produces a support
 * call, not a security incident. So:
 *
 *  - the alphabet leaves out `0`/`O`, `1`/`l`/`I` — the five characters that
 *    are actually confused;
 *  - the result is grouped as `Xk9m-Pq4t-Wz7n-Rb3d`, which people read back
 *    reliably and mail clients accept unchanged.
 *
 * The cost of the readable alphabet is paid in length, not in strength: 16
 * characters from 57 give about 93 bits, well past anything the block list or a
 * bcrypt hash needs to withstand.
 *
 * Every suggestion is run through {@see PasswordPolicy}, so a generated
 * password can never be one the same policy would refuse when typed.
 */
final class PasswordGenerator
{
    /**
     * 57 characters: `0`, `O`, `1`, `l` and `I` are absent.
     *
     * `5`/`S` and `2`/`Z` remain — they are distinguishable when spoken, unlike
     * the five above, and removing them would cost entropy for nothing.
     */
    public const string ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public const int GROUP_LENGTH = 4;
    public const int GROUP_COUNT = 4;
    public const string GROUP_SEPARATOR = '-';

    /**
     * A generated password that the policy refuses is a lottery win — the block
     * list would have to contain it, or it would have to hold the user's own
     * name. Trying a few more times is cheaper than reasoning about it.
     */
    private const int MAXIMUM_ATTEMPTS = 16;

    public function __construct(private readonly PasswordPolicy $policy)
    {
    }

    /**
     * @throws RuntimeException if no acceptable password could be produced
     */
    public function generate(PasswordSubject $subject, ?PersonalTerms $terms = null): Password
    {
        $lastRefusal = InvalidPassword::empty();

        for ($attempt = 0; $attempt < self::MAXIMUM_ATTEMPTS; ++$attempt) {
            try {
                return $this->policy->accept($this->candidate(), $subject, $terms);
            } catch (InvalidPassword $refused) {
                $lastRefusal = $refused;
            }
        }

        throw new RuntimeException(sprintf(
            'Could not generate a password for %s that the policy accepts after %d attempts; '
            . 'the last refusal was "%s". Check the generator settings against U-21.',
            $subject->table(),
            self::MAXIMUM_ATTEMPTS,
            $lastRefusal->code(),
        ));
    }

    /**
     * The raw suggestion, before the policy sees it.
     *
     * `random_int()` — the CSPRNG, not `rand()` or `mt_rand()`. It throws rather
     * than returning a predictable value when the system has no entropy source,
     * which is the behaviour to want here.
     */
    private function candidate(): string
    {
        $last = strlen(self::ALPHABET) - 1;
        $groups = [];

        for ($group = 0; $group < self::GROUP_COUNT; ++$group) {
            $characters = '';

            for ($position = 0; $position < self::GROUP_LENGTH; ++$position) {
                $characters .= self::ALPHABET[random_int(0, $last)];
            }

            $groups[] = $characters;
        }

        return implode(self::GROUP_SEPARATOR, $groups);
    }
}
