<?php

declare(strict_types=1);

namespace Mailadmin\Application\Auth;

/**
 * Where the refresh token goes back to the client.
 *
 * The client says which, and the server does not guess from a `User-Agent` or
 * an `Accept` header. Guessing would mean a browser could be talked into the
 * body variant by sending an unusual header — and the body variant is precisely
 * the one that puts a twelve-hour credential where a script can read it.
 *
 * `Cookie` is the default, so the safe behaviour is what a client that says
 * nothing gets.
 */
enum RefreshDelivery: string
{
    /** HttpOnly cookie, nothing in the body. What a browser must use (E-15). */
    case Cookie = 'cookie';

    /** In the response body, no cookie. For `curl`, tests and non-browser clients. */
    case Body = 'body';

    /**
     * An unrecognised value becomes {@see Cookie}. The contract's `enum` already
     * rejects anything else with a `422`; this is the second line of defence,
     * and it fails towards the safer of the two.
     */
    public static function fromRequestValue(?string $value): self
    {
        return $value === null ? self::Cookie : (self::tryFrom($value) ?? self::Cookie);
    }

    public function isCookie(): bool
    {
        return $this === self::Cookie;
    }
}
