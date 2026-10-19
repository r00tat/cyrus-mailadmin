<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model;

use Mailadmin\Domain\Exception\InvalidAddress;
use Mailadmin\Domain\Exception\InvalidMailboxIdentity;

/**
 * The one place that knows the several spellings of one account.
 *
 * ```text
 * Address                greta.mustermann@example.test      (always the same)
 *
 * dotted   Cyrus user    greta.mustermann.example.test
 *          IMAP mailbox  user/greta.mustermann.example.test
 *          spool         greta^mustermann^example^test
 *
 * fqun     Cyrus user    greta.mustermann@example.test
 *          IMAP mailbox  user/greta.mustermann@example.test
 *          spool         domain/e/example.test/g/user/greta^mustermann
 * ```
 *
 * **The Cyrus user name is generated, never taken apart.** From
 * `greta.mustermann.example.test` alone there is no way to say where the local
 * part ends — a local part may contain dots. The information exists in exactly
 * one place, `accountuser.domain_name`. Therefore:
 *
 *  - the constructor is private, and {@see create()} takes the triple (local
 *    part, domain, scheme) — there is no way to build an identity from a user
 *    name alone;
 *  - {@see fromStoredUsername()} is the only recognition path, and it demands
 *    the domain of record next to the name. It asks `NamingScheme::detect()`
 *    whether there is an `@` and then removes the domain **by length**, never
 *    with a `REPLACE`. The legacy view `allusers` uses `REPLACE(username,
 *    CONCAT('.', domain_name), '')`, which removes *every* occurrence and
 *    returns nonsense for a local part that contains the domain name
 *    (architecture 3.3). We do not change the view (E-1); we do not repeat it.
 *
 * Recognition keeps the stored bytes: `fromStoredUsername($name, $domain)
 * ->cyrusUsername()` returns `$name` again, character for character. That is
 * deliberate — `accountuser.username` is a `varchar binary` column and the
 * mailbox in Cyrus carries exactly this spelling. A generated name that differs
 * in case would rename the wrong mailbox.
 */
final readonly class MailboxIdentity
{
    /**
     * Cyrus mangles a dot in a mailbox name to `^` on disk (`unixhierarchysep`).
     */
    private const string SPOOL_DOT = '^';

    /**
     * What Cyrus uses when the first character of a name is not a letter.
     *
     * `dir_hash_c()` lower-cases the first character and falls back to `q` for
     * anything that is not an ASCII letter. **Assumption**, taken from the Cyrus
     * source; the spool path is diagnostic output only — the application never
     * touches the file system — so a wrong hash character misleads a reader but
     * breaks nothing. To be confirmed against a real spool in F5.
     */
    private const string SPOOL_HASH_FALLBACK = 'q';

    private function __construct(
        public LocalPart $localPart,
        public DomainName $domain,
        public NamingScheme $scheme,
    ) {
    }

    /**
     * Build an identity from the triple. The only way to make a new one.
     */
    public static function create(
        LocalPart $localPart,
        DomainName $domain,
        NamingScheme $scheme,
    ): self {
        return new self($localPart, $domain, $scheme);
    }

    /**
     * Recognise the identity of an account that already exists.
     *
     * @param string     $storedUsername `accountuser.username`, verbatim
     * @param DomainName $domainOfRecord `accountuser.domain_name` — the only
     *                                   source for where the local part ends
     *
     * @throws InvalidMailboxIdentity when name and domain contradict each other
     * @throws InvalidAddress         when the remaining local part is unusable
     */
    public static function fromStoredUsername(
        string $storedUsername,
        DomainName $domainOfRecord,
    ): self {
        $username = trim($storedUsername);

        if ($username === '') {
            throw InvalidMailboxIdentity::emptyUsername();
        }

        $scheme = NamingScheme::detect($username);

        return match ($scheme) {
            NamingScheme::Fqun => self::recogniseFqun($username, $domainOfRecord),
            NamingScheme::Dotted => self::recogniseDotted($username, $domainOfRecord),
        };
    }

    /**
     * The address. Independent of the scheme — this is what a sender writes.
     */
    public function address(): EmailAddress
    {
        return new EmailAddress($this->localPart, $this->domain);
    }

    /**
     * The name in `accountuser.username`, and the name used to log in.
     *
     * This is the account's identity in the API as well (U-18): the `{id}` in
     * an account path is this string, URL-encoded by the HTTP layer.
     */
    public function cyrusUsername(): string
    {
        return match ($this->scheme) {
            NamingScheme::Dotted => $this->localPart->toString() . '.' . $this->domain->toString(),
            NamingScheme::Fqun => $this->localPart->toString() . '@' . $this->domain->toString(),
        };
    }

    /**
     * The INBOX in Cyrus's internal notation, with `unixhierarchysep: yes`.
     */
    public function imapMailbox(): string
    {
        return $this->imapFolder(null);
    }

    /**
     * A folder below the INBOX, e.g. `Sent`.
     *
     * With virtual domains the domain stays at the *end* of the whole mailbox
     * name, so a `fqun` folder reads `user/greta.mustermann/Sent@example.test`
     * and not `user/greta.mustermann@example.test/Sent`. Getting this wrong
     * creates a mailbox nobody sees. **Assumption** from the Cyrus namespace
     * rules, to be confirmed by M2 against F5.
     */
    public function imapFolder(?string $folder): string
    {
        $suffix = $folder === null || $folder === '' ? '' : '/' . trim($folder, '/');

        return match ($this->scheme) {
            NamingScheme::Dotted => 'user/' . $this->cyrusUsername() . $suffix,
            NamingScheme::Fqun => 'user/' . $this->localPart->toString() . $suffix
                . '@' . $this->domain->toString(),
        };
    }

    /**
     * Where the mailbox lives on disk, in the two forms from architecture 3.3.
     *
     * For `dotted` that is the mangled name below the partition's `user/`
     * directory; for `fqun` the path starts at the partition root, because the
     * domain namespace is part of it. Diagnostic output — nothing in the
     * application reads or writes the spool.
     */
    public function spoolPath(): string
    {
        return match ($this->scheme) {
            NamingScheme::Dotted => $this->spoolName(),
            NamingScheme::Fqun => sprintf(
                'domain/%s/%s/%s/user/%s',
                self::hashCharacter($this->domain->toString()),
                $this->domain->toString(),
                self::hashCharacter($this->localPart->toString()),
                $this->spoolName(),
            ),
        };
    }

    /**
     * The mangled leaf name: dots become `^`.
     */
    public function spoolName(): string
    {
        $name = match ($this->scheme) {
            NamingScheme::Dotted => $this->cyrusUsername(),
            NamingScheme::Fqun => $this->localPart->toString(),
        };

        return str_replace('.', self::SPOOL_DOT, $name);
    }

    /**
     * The same account under a different scheme.
     *
     * The result is a *target*, not a fact: getting there means
     * `renamemailbox` across the mailbox and new credentials for the user
     * (architecture 8.1). Nothing here performs that.
     */
    public function withScheme(NamingScheme $scheme): self
    {
        if ($scheme === $this->scheme) {
            return $this;
        }

        return new self($this->localPart, $this->domain, $scheme);
    }

    public function withDomain(DomainName $domain): self
    {
        return new self($this->localPart, $domain, $this->scheme);
    }

    /**
     * Is this the name that is actually stored for the account?
     *
     * Compares case insensitively, so a legacy row spelled in mixed case is
     * recognised as the same mailbox instead of quietly becoming a second one.
     */
    public function matchesStoredUsername(string $storedUsername): bool
    {
        return strtolower(trim($storedUsername)) === strtolower($this->cyrusUsername());
    }

    public function equals(self $other): bool
    {
        return $this->scheme === $other->scheme
            && $this->localPart->equals($other->localPart)
            && $this->domain->equals($other->domain);
    }

    /**
     * @throws InvalidMailboxIdentity
     * @throws InvalidAddress
     */
    private static function recogniseDotted(string $username, DomainName $domainOfRecord): self
    {
        $suffix = '.' . $domainOfRecord->toString();

        if (!str_ends_with(strtolower($username), strtolower($suffix))) {
            throw InvalidMailboxIdentity::dottedUsernameDoesNotEndInDomain(
                $username,
                $domainOfRecord->toString(),
            );
        }

        $cut = strlen($username) - strlen($suffix);

        if ($cut <= 0) {
            throw InvalidMailboxIdentity::localPartWouldBeEmpty(
                $username,
                $domainOfRecord->toString(),
            );
        }

        // Keep the stored spelling of the domain, not the one from the column:
        // cyrusUsername() has to reproduce the stored name byte for byte.
        return new self(
            LocalPart::fromStoredValue(substr($username, 0, $cut)),
            DomainName::fromStoredValue(substr($username, $cut + 1)),
            NamingScheme::Dotted,
        );
    }

    /**
     * @throws InvalidMailboxIdentity
     * @throws InvalidAddress
     */
    private static function recogniseFqun(string $username, DomainName $domainOfRecord): self
    {
        $position = strrpos($username, '@');

        if ($position === false) {
            // Unreachable: NamingScheme::detect() only answers Fqun when there
            // is an "@". Kept as a guard rather than an assertion, because the
            // alternative is a silently wrong offset.
            throw InvalidMailboxIdentity::emptyUsername();
        }

        $storedDomain = substr($username, $position + 1);

        if (strtolower($storedDomain) !== strtolower($domainOfRecord->toString())) {
            throw InvalidMailboxIdentity::fqunUsernameHasOtherDomain(
                $username,
                $domainOfRecord->toString(),
            );
        }

        if ($position === 0) {
            throw InvalidMailboxIdentity::localPartWouldBeEmpty(
                $username,
                $domainOfRecord->toString(),
            );
        }

        return new self(
            LocalPart::fromStoredValue(substr($username, 0, $position)),
            DomainName::fromStoredValue($storedDomain),
            NamingScheme::Fqun,
        );
    }

    private static function hashCharacter(string $name): string
    {
        $first = strtolower(substr($name, 0, 1));

        return preg_match('/^[a-z]$/', $first) === 1 ? $first : self::SPOOL_HASH_FALLBACK;
    }
}
