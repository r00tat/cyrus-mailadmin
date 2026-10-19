<?php

declare(strict_types=1);

namespace Mailadmin\Application\Domain;

use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Model\DomainName;

/**
 * What `PATCH /domains/{domain}` asks for.
 *
 * Two things, kept apart because they are not the same kind of change:
 *
 *  - **the attributes**, which are one `UPDATE` ({@see DomainAttributes});
 *  - **the name**, which is `renamemailbox` over every account of the domain
 *    before the database is touched at all (architecture 8.1).
 *
 * {@see renameTarget()} answers `null` when the body did not ask for a rename —
 * including when it sent the name the domain already has, spelled the same way.
 * A different **spelling** of the same name is a rename all the same: `virtual`
 * and `accountuser` store the exact bytes, and Cyrus knows the mailbox under
 * them, so changing `Example.test` to `example.test` moves mailboxes exactly
 * like any other rename. Treating it as a no-op would leave the database and the
 * mail store disagreeing about capitalisation, which is the class of bug this
 * package exists to avoid.
 */
final readonly class DomainChange
{
    public const string NAME = 'name';
    public const string CONFIRM_RENAME = 'confirm_rename';

    private function __construct(
        private ?string $requestedName,
        private bool $confirmed,
        public DomainAttributes $attributes,
    ) {
    }

    /**
     * @param array<string, mixed> $body the validated request body
     */
    public static function fromRequest(array $body): self
    {
        /** @var mixed $name */
        $name = $body[self::NAME] ?? null;
        /** @var mixed $confirm */
        $confirm = $body[self::CONFIRM_RENAME] ?? false;

        return new self(
            is_string($name) ? $name : null,
            $confirm === true,
            DomainAttributes::fromRequest($body),
        );
    }

    /**
     * The name to rename to, or `null` when this request does not rename.
     *
     * @throws DomainError when the requested name is not one this application
     *                     would issue
     */
    public function renameTarget(DomainName $current): ?DomainName
    {
        if ($this->requestedName === null) {
            return null;
        }

        $target = DomainName::fromString($this->requestedName);

        return $target->toString() === $current->toString() ? null : $target;
    }

    /**
     * Did the body carry `confirm_rename: true`?
     *
     * Deliberately strict about the value: anything other than the boolean
     * `true` is not a confirmation. The one thing worse than a rename that needs
     * an extra field is a rename that a truthy `"no"` sets off.
     */
    public function confirmsRename(): bool
    {
        return $this->confirmed;
    }

    /**
     * Is there anything to do at all?
     *
     * The contract requires at least one property, so this is false for every
     * request that reaches a handler. It exists for the callers that build a
     * change without an HTTP request in sight.
     */
    public function isEmpty(): bool
    {
        return $this->requestedName === null && $this->attributes->isEmpty();
    }
}
