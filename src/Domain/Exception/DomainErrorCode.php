<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Exception;

/**
 * The stable error codes the domain model raises.
 *
 * Codes follow `<resource>.<state>` in `snake_case` (U-7) and use only the
 * resource prefixes that decision lists. They are what the frontend translates
 * (E-16); the exception message is an English log line and never reaches a
 * client.
 *
 * The authoritative list is the `ErrorCode` enum in the OpenAPI contract
 * (`openapi/components/common.yaml`, owned by F2) — every constant here has to
 * be added there. The constants exist so that no layer spells a code as a
 * literal.
 */
final class DomainErrorCode
{
    /** A domain name that is not usable at all — empty, with `@`, or too long. */
    public const string DOMAIN_INVALID_NAME = 'domain.invalid_name';

    /** A default folder name Cyrus could not carry. */
    public const string DOMAIN_INVALID_FOLDER = 'domain.invalid_folder';

    /** A negative or otherwise impossible account limit. */
    public const string DOMAIN_INVALID_ACCOUNT_LIMIT = 'domain.invalid_account_limit';

    /** A local part that is not usable at all. */
    public const string ADDRESS_INVALID_LOCAL_PART = 'address.invalid_local_part';

    /** A mail address that cannot be split into local part and domain. */
    public const string ADDRESS_INVALID = 'address.invalid';

    /** A `virtual.dest` value that carries no usable destination. */
    public const string ADDRESS_INVALID_DESTINATION = 'address.invalid_destination';

    /** A stored Cyrus user name that is empty or otherwise unusable. */
    public const string ACCOUNT_INVALID_IDENTITY = 'account.invalid_identity';

    /**
     * The stored Cyrus user name does not belong to `accountuser.domain_name`.
     *
     * The pair is the only source for where the local part ends (architecture
     * 3.3). If they contradict each other, nothing can be derived — guessing
     * would be exactly the mistake this model exists to prevent.
     */
    public const string ACCOUNT_IDENTITY_MISMATCH = 'account.identity_mismatch';

    /** A quota value that is negative or exceeds what Cyrus accepts. */
    public const string QUOTA_INVALID = 'quota.invalid';

    /** An ACL string with a right Cyrus does not know. */
    public const string MAILSTORE_INVALID_ACL = 'mailstore.invalid_acl';

    /** A Sieve rule that could not be rendered into a valid script. */
    public const string SIEVE_INVALID_RULE = 'sieve.invalid_rule';

    /** An actor context that claims a role and a scope which contradict. */
    public const string ADMIN_INVALID_SCOPE = 'admin.invalid_scope';

    // ------------------------------------------------------------------
    // A5 — mailing lists. All of them are raised for **new or changed** values
    // only; a row that is already in `lists` is shown, never rejected (U-22).
    // ------------------------------------------------------------------

    /** A mailing list name that is empty, longer than 255 characters or carries a control character. */
    public const string LIST_INVALID_NAME = 'list.invalid_name';

    /**
     * A mailing list name with a character the latin1 column cannot store —
     * an emoji, `€`, a C1 control. Its own code because the fix is different:
     * the name is fine as a label, the table is not.
     */
    public const string LIST_NAME_OUTSIDE_LATIN1 = 'list.name_outside_latin1';

    /** A mailing list tag that is too long or carries a control character. */
    public const string LIST_INVALID_TAG = 'list.invalid_tag';

    /** A mailing list tag with a character the latin1 column cannot store. */
    public const string LIST_TAG_OUTSIDE_LATIN1 = 'list.tag_outside_latin1';

    /** A mailing list address that is not a mail address, or does not fit the column. */
    public const string LIST_INVALID_ADDRESS = 'list.invalid_address';

    /**
     * A member being added is not a mail address, or the rendered list would not
     * fit the column.
     *
     * The first half is a security rule rather than a validation nicety — see
     * {@see InvalidMailingList::memberNotAnAddress()}.
     */
    public const string LIST_INVALID_MEMBER = 'list.invalid_member';

    /**
     * A member entry with a character the latin1 column cannot store. Reported
     * at the entry's position (`body.members.<n>`), never with its text.
     */
    public const string LIST_MEMBER_OUTSIDE_LATIN1 = 'list.member_outside_latin1';

    /**
     * The actor's role does not carry the permission the operation needs (S3).
     *
     * `request.` rather than a resource prefix, and that is the whole point: the
     * refusal is about the operation, not about any object, so the code must not
     * name a resource that the caller might not be allowed to know about.
     */
    public const string REQUEST_FORBIDDEN = 'request.forbidden';

    /**
     * The object is outside the actor's domain scope (S3).
     *
     * Identical to the code for an object that does not exist, deliberately:
     * CLAUDE.md requires that a foreign object answer `404`, and a distinct code
     * would undo that in the one field a client actually reads.
     */
    public const string REQUEST_NOT_FOUND = 'request.not_found';

    private function __construct()
    {
    }
}
