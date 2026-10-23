<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Policy\ListPolicy;
use Mailadmin\Domain\Policy\ResourceNotVisible;
use Mailadmin\Infrastructure\Persistence\MailingListRepository;

/**
 * The steps every mailing list operation that names one begins with.
 *
 * Written once rather than four times, because each of them is a place where
 * getting it subtly wrong would not fail a test:
 *
 *  - **A value that is no address answers `404`, not `422`** — otherwise the
 *    shape of the refusal tells a caller whether their guess was merely absent
 *    or malformed.
 *  - **A missing list answers with the framework's own `404` code**, and a
 *    list outside the actor's domains with `ResourceNotVisible`, which the
 *    middleware turns into the same answer — see
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
     * The list with this address, if the actor may see it.
     *
     * The address is parsed tolerantly, because it names a stored row; the
     * table may hold an address this application would not hand out.
     *
     * @throws MailingListFailure when there is no such list
     * @throws ResourceNotVisible when the list is in a domain the actor does not manage
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public static function load(MailingListRepository $repository, ActorContext $actor, string $address): MailingList
    {
        $wanted = self::parse($address);

        $list = $repository->find($wanted) ?? throw MailingListFailure::notFound($address);

        ListPolicy::requireVisible($actor, self::addressOf($list));

        return $list;
    }

    /**
     * The identity of a stored list.
     *
     * @throws MailingListFailure when the stored address is not an address at
     *                            all, so the row cannot be addressed
     */
    public static function addressOf(MailingList $list): EmailAddress
    {
        return self::parse($list->address);
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
            throw MailingListFailure::preconditionFailed($list->address);
        }
    }

    /**
     * @throws MailingListFailure
     */
    private static function parse(string $address): EmailAddress
    {
        try {
            return EmailAddress::parseStored(trim($address));
        } catch (InvalidAddress) {
            throw MailingListFailure::notFound($address);
        }
    }
}
