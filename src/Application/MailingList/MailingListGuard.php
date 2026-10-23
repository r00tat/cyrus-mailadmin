<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\MailingList\MailingListName;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\MailingListRepository;

/**
 * The three steps every mailing list operation that names one begins with.
 *
 * Written once rather than four times, because each of them is a place where
 * getting it subtly wrong would not fail a test:
 *
 *  - **A name no list carries answers `404`, not `422`** — whatever it looks
 *    like, since a stored name may hold anything. Otherwise the shape of the
 *    refusal tells a caller whether their guess was merely absent or malformed.
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

    public static function name(string $value): MailingListName
    {
        return MailingListName::fromStoredValue($value);
    }

    /**
     * The list the path value names.
     *
     * A list is identified by its address now, but the HTTP contract still
     * carries the display name in the path, so the name is resolved through the
     * listing until the contract moves to the address. Names may repeat; the
     * first match wins, which is exactly the ambiguity that move removes.
     *
     * @throws MailingListFailure when there is no such list
     */
    public static function load(MailingListRepository $repository, ActorContext $actor, string $name): MailingList
    {
        $wanted = self::name($name);

        $page = $repository->list($actor, new ListCriteria(
            perPage: ListCriteria::MAX_PER_PAGE,
            filters: ['name' => $wanted->toString()],
        ));

        foreach ($page->items as $list) {
            if ($list->name->equals($wanted)) {
                return $list;
            }
        }

        throw MailingListFailure::notFound($name);
    }

    /**
     * The identity of a stored list.
     *
     * @throws MailingListFailure when the stored address is not an address at
     *                            all, so the row cannot be addressed
     */
    public static function addressOf(MailingList $list): EmailAddress
    {
        try {
            return EmailAddress::parseStored($list->address);
        } catch (InvalidAddress) {
            throw MailingListFailure::notFound($list->name->toString());
        }
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
