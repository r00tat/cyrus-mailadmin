<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

use Mailadmin\Domain\Exception\InvalidMailingList;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\MailingList\MailingListName;
use Mailadmin\Infrastructure\Persistence\MailingListRepository;

/**
 * The three steps every mailing list operation that names one begins with.
 *
 * Written once rather than four times, because each of them is a place where
 * getting it subtly wrong would not fail a test:
 *
 *  - **A name that could never be a row answers `404`, not `422`.** Otherwise
 *    the shape of the refusal tells a caller whether their guess was merely
 *    absent or malformed.
 *  - **A missing list answers with the framework's own `404` code**, so that it
 *    is indistinguishable from a list somebody may not see — see
 *    {@see MailingListErrorCode::NOT_FOUND}.
 *  - **`If-Match` is optional but never ignored.** Architecture 7.1 says writing
 *    methods *accept* the header and a mismatch is `412`; a handler that read it
 *    and forgot to compare would look identical from the outside until two
 *    administrators overwrote each other.
 */
final class MailingListGuard
{
    private function __construct()
    {
    }

    /**
     * @throws MailingListFailure when the value cannot name a row at all
     */
    public static function name(string $value): MailingListName
    {
        try {
            return MailingListName::fromStoredValue($value);
        } catch (InvalidMailingList) {
            throw MailingListFailure::notFound($value);
        }
    }

    /**
     * @throws MailingListFailure when there is no such list
     */
    public static function load(MailingListRepository $repository, string $name): MailingList
    {
        return $repository->find(self::name($name)) ?? throw MailingListFailure::notFound($name);
    }

    /**
     * @throws MailingListFailure when an `If-Match` was sent and does not match
     */
    public static function precondition(MailingList $list, ?string $ifMatch): void
    {
        if ($ifMatch === null || trim($ifMatch) === '') {
            return;
        }

        if (!MailingListView::matchesEtag($list, $ifMatch)) {
            throw MailingListFailure::preconditionFailed($list->name->toString());
        }
    }
}
