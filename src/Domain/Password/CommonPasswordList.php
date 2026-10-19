<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Password;

/**
 * The block list of common passwords U-21 asks for.
 *
 * An interface because the list itself is a file, and a file is I/O — the
 * domain layer states the question, `Infrastructure\Security` answers it from
 * `resources/common-passwords.txt`. It also makes the rule testable without a
 * 100 KB fixture; see {@see InMemoryCommonPasswordList}.
 *
 * **The comparison is case-insensitive and exact.** `Password123` is blocked
 * because `password123` is on the list; `password123!` is not, because it is
 * not on the list. Stripping suffixes or normalising leetspeak would catch more
 * and would also start refusing passwords for reasons nobody can explain in a
 * form — if that is wanted, it is a decision, not an implementation detail.
 *
 * **An implementation must never log the candidate**, and must fail loudly when
 * its source is missing rather than answering `false`: a block list that
 * quietly disappears is worse than none, because nobody notices.
 */
interface CommonPasswordList
{
    public function contains(string $candidate): bool;
}
