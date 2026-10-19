<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Policy;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Exception\DomainErrorCode;
use Mailadmin\Domain\Model\DomainName;

/**
 * The object lies outside the actor's domain scope — `404`, not `403`.
 *
 * **This is not an error class that happens to be mapped to `404`; the `404` is
 * the point.** A domain master who guesses an account id in somebody else's
 * domain must get the same answer as for an id that does not exist. Anything
 * else lets them enumerate — try ids, watch which ones say "forbidden" instead
 * of "not found", and the shape of every other customer's installation falls
 * out.
 *
 * The consequence for callers: **throw this before saying anything else about
 * the object.** A handler that reads a record, formats it and then asks the
 * policy has already spent the time that a timing comparison needs. Ask first.
 *
 * The message names the domain, because the log reader is an operator who
 * already knows it. The response says only `request.not_found`.
 */
final class ResourceNotVisible extends PolicyRefusal
{
    private function __construct(
        public readonly string $username,
        string $message,
        string $domain,
    ) {
        parent::__construct(
            DomainErrorCode::REQUEST_NOT_FOUND,
            $message,
            ['domain' => self::excerpt($domain)],
        );
    }

    public static function domain(ActorContext $actor, DomainName $domain): self
    {
        return new self($actor->username, sprintf(
            'The domain "%s" is outside the scope of "%s".',
            $domain->toString(),
            self::excerpt($actor->username),
        ), $domain->toString());
    }

    /**
     * An object that lives in a domain the actor cannot see.
     *
     * `$what` names the kind — "account", "alias", "catchall" — never the
     * identifier: the identifier came from the request, and echoing it into a
     * log line is how an unprintable byte gets there.
     */
    public static function inDomain(ActorContext $actor, string $what, DomainName $domain): self
    {
        return new self($actor->username, sprintf(
            'A %s in "%s" is outside the scope of "%s".',
            self::excerpt($what, 40),
            $domain->toString(),
            self::excerpt($actor->username),
        ), $domain->toString());
    }
}
