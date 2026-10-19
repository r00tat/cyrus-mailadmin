<?php

declare(strict_types=1);

namespace Mailadmin\Http;

/**
 * The security headers from architecture 5.7, verbatim, in one place.
 *
 * They live in a constant rather than inside the middleware because the front
 * controller's last-resort error path also needs them: a response produced
 * because the container could not even be built must not be the one response
 * that ships without a Content-Security-Policy.
 *
 * **The CSP is load-bearing, and `script-src` is the part that bears the load.**
 * It carries the residual risk accepted in E-15 — the access token in
 * `localStorage` — and stealing that token requires script execution. So
 * `script-src 'self'` stays as it is, and `unsafe-eval` appears nowhere. If the
 * bundler emits inline scripts, the bundler is changed, not the policy.
 *
 * `style-src` carries `'unsafe-inline'` since U-35: Material UI styles at
 * runtime through Emotion, which writes `<style>` elements into the document,
 * and for statically served pages MUI documents no other way. Injected *styles*
 * cannot reach the token; what is lost is defence against defacement, and
 * CSS-based exfiltration stays largely blocked because no external origin is
 * reachable at all. Any *further* loosening remains an escalation case
 * (detailed design, section 5).
 *
 * Note that this policy only reaches responses produced by PHP, i.e. `/api/*`.
 * The statically served SPA shell needs the same headers from the web server —
 * open point O-13, to be closed by W1.
 */
final class SecurityHeaders
{
    public const string CONTENT_SECURITY_POLICY =
        "default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; "
        . "connect-src 'self'; "
        . "frame-ancestors 'none'; "
        . "base-uri 'none'; "
        . "object-src 'none'";

    /**
     * `Cache-Control` is not part of this list. It is applied separately, so
     * that a response which legitimately may be cached — the built frontend
     * bundle, once W1 exists — can opt out without touching the security
     * headers.
     *
     * @var array<string, string>
     */
    public const array HEADERS = [
        'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
    ];

    /**
     * Management data does not belong in any cache (architecture 5.7).
     */
    public const string CACHE_CONTROL = 'no-store';

    private function __construct()
    {
    }
}
