<?php

declare(strict_types=1);

namespace Mailadmin\Application\Audit;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Page;
use Mailadmin\Infrastructure\Persistence\PersistenceError;

/**
 * Reading the trail back.
 *
 * A separate interface from {@see AuditWriter}, and not because the two could
 * have different implementations — they will not. It is separate because almost
 * everything in the application writes and exactly one thing reads: a use case
 * that is handed the reader as well could list other people's entries, and a use
 * case that never needs to should not be able to.
 *
 * The narrowing lives in the implementation, over `Repository::scopedTo()`
 * (U-45), rather than in the caller. `audit.read` is superuser-only today
 * (U-43), so it narrows nothing — but the day it is not, the listing is already
 * scoped, which is not something one wants to discover afterwards.
 */
interface AuditReader
{
    /**
     * @return Page<AuditRecord>
     *
     * @throws CriteriaError    when the request names a sort or filter field that is not offered
     * @throws PersistenceError when the query fails
     */
    public function list(ActorContext $actor, ListCriteria $criteria): Page;
}
