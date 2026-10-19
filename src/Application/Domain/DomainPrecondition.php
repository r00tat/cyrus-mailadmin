<?php

declare(strict_types=1);

namespace Mailadmin\Application\Domain;

/**
 * The `If-Match` check both writing operations make (U-19, U-49).
 *
 * One class rather than a private method in each use case, because "required,
 * then compared" is a rule and not a convenience: a second copy is a second
 * place where the `428` could quietly become a `412`, or the requirement could
 * quietly become optional.
 *
 * `428` for a missing header and `412` for a stale one is the whole distinction:
 * the first tells a client to read the resource and try again, the second tells
 * it that somebody else got there first. Collapsing them into one status —
 * which is what a `422` would do — loses that.
 */
final class DomainPrecondition
{
    private function __construct()
    {
    }

    /**
     * @throws DomainOperationFailed when the header is absent or names another
     *                               version of the domain
     */
    public static function require(
        DomainSummary $current,
        DomainDefaults $defaults,
        ?string $ifMatch,
    ): void {
        if ($ifMatch === null || trim($ifMatch) === '') {
            throw DomainOperationFailed::preconditionRequired();
        }

        $etag = DomainView::etag(DomainView::full($current, $defaults));

        if (!DomainView::matchesEtag($ifMatch, $etag)) {
            throw DomainOperationFailed::preconditionFailed();
        }
    }
}
