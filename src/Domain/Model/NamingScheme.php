<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model;

/**
 * How an account's Cyrus user name is built (E-14, architecture 3.3).
 *
 * ```text
 * Address              greta.mustermann@example.test      (always the same)
 *
 * dotted   user name   greta.mustermann.example.test
 * fqun     user name   greta.mustermann@example.test
 * ```
 *
 * Two rules keep this manageable, and both live here:
 *
 * 1. **`detect()` is the only decomposition that ever happens.** Does the
 *    stored user name contain an `@`? That question is answered without
 *    ambiguity, because an `@` in a Cyrus user name can only be the domain
 *    separator. Everything else about the name — above all where the local part
 *    ends — is not derivable and is not guessed.
 * 2. **Configuration only decides what *new* accounts get** — `resolve()`, in
 *    the three steps from architecture 3.3. An existing account keeps the
 *    scheme its mailbox actually lives under, whatever the configuration says.
 *
 * Moving an account between schemes is a real operation (`renamemailbox`, and
 * the user has to change their credentials), never a side effect of a setting.
 */
enum NamingScheme: string
{
    /** `local.domain.tld` — one Cyrus namespace, no virtual domains. */
    case Dotted = 'dotted';

    /**
     * `local@domain.tld` — the fully qualified user name. Needs the domain in
     * the Cyrus `loginrealms`, which Salt writes and we cannot check (E-9).
     */
    case Fqun = 'fqun';

    /** The last step of {@see resolve()}: what applies when nothing is set. */
    public const self FALLBACK = self::Dotted;

    /**
     * Recognise the scheme of an account that already exists.
     *
     * The **only** permitted evaluation of a stored Cyrus user name. It answers
     * one question and no other; to get from the name to a local part you need
     * {@see MailboxIdentity::fromStoredUsername()}, which also wants
     * `accountuser.domain_name`.
     */
    public static function detect(string $storedUsername): self
    {
        return str_contains($storedUsername, '@') ? self::Fqun : self::Dotted;
    }

    /**
     * What a *new* account in this domain gets, in three steps (architecture
     * 3.3): the domain setting from `mailadmin_domain_setting`, otherwise the
     * global default `MAILADMIN_NAMING_SCHEME`, otherwise `dotted`.
     */
    public static function resolve(?self $domainSetting, ?self $globalDefault): self
    {
        return $domainSetting ?? $globalDefault ?? self::FALLBACK;
    }

    /**
     * `fqun` requires the domain to be listed in the Cyrus `loginrealms`. Salt
     * owns that file (E-9) — the interface warns, it cannot verify.
     */
    public function requiresLoginRealm(): bool
    {
        return $this === self::Fqun;
    }
}
