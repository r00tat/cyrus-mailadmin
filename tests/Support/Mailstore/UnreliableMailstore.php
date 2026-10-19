<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Support\Mailstore;

use Mailadmin\Domain\Mailstore\Acl;
use Mailadmin\Domain\Mailstore\AclSet;
use Mailadmin\Domain\Mailstore\Mailstore;
use Mailadmin\Domain\Mailstore\Quota;
use Mailadmin\Domain\Model\DefaultFolders;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Infrastructure\Mailstore\ConnectionFailed;
use Mailadmin\Infrastructure\Mailstore\InMemoryMailstore;
use Mailadmin\Infrastructure\Mailstore\MailboxOperationFailed;
use Mailadmin\Infrastructure\Mailstore\ReadTimedOut;

/**
 * A mail store that can be told to fail a named operation.
 *
 * {@see InMemoryMailstore} is deliberately strict rather than steerable: it
 * refuses what Cyrus refuses and succeeds where Cyrus succeeds. That is the
 * right shape for testing what a use case does when things go **right**, and it
 * cannot produce the two situations a domain rename is written around:
 *
 *  - the third of five `renamemailbox` calls fails;
 *  - the compensating rename back fails too, so a mailbox is left under a name
 *    no database row mentions.
 *
 * Neither can be arranged by putting the store into a clever state — the second
 * one in particular requires the *same* rename to succeed once and fail once. So
 * this decorator sits in front of the real double and fails on demand,
 * delegating everything else.
 *
 * It lives in `tests/` and not in `src/`, unlike the doubles: it has no
 * behaviour of its own to be the reference for. It is a lever.
 */
final class UnreliableMailstore implements Mailstore
{
    /** @var array<string, int> "from -> to" => how many times to fail it */
    private array $failures = [];

    /** @var array<string, int> operation name => how many times to fail it */
    private array $operationFailures = [];

    /** @var array<string, int> operation name => how many calls to let through first */
    private array $operationGrace = [];

    /** @var array<string, bool> operation name => whether it takes effect before the answer times out */
    private array $lostAnswers = [];

    private bool $unreachable = false;

    public function __construct(private readonly InMemoryMailstore $inner = new InMemoryMailstore())
    {
    }

    public function inner(): InMemoryMailstore
    {
        return $this->inner;
    }

    /**
     * Fail this rename the next `$times` times it is attempted.
     */
    public function failRename(MailboxIdentity $from, MailboxIdentity $to, int $times = 1): void
    {
        $this->failures[self::key($from, $to)] = $times;
    }

    /**
     * Fail the named operation (`createMailbox`, `setAcl`, `setQuota`,
     * `getQuota`, `createDefaultFolders`, `deleteMailbox`) the next `$times`
     * times it is called, whatever the mailbox — the lever A2's compensation
     * tests need. `$after` lets that many calls succeed first — the change
     * goes through, the compensation does not.
     */
    public function failNext(string $operation, int $times = 1, int $after = 0): void
    {
        $this->operationFailures[$operation] = $times;
        $this->operationGrace[$operation] = $after;
    }

    /**
     * Time out waiting for the tagged answer of the next call of the named
     * operation — the ambiguous failure. With `$tookEffect` the command ran
     * first (the answer was lost), without it the command never arrived.
     */
    public function loseAnswerNext(string $operation, bool $tookEffect = true): void
    {
        $this->lostAnswers[$operation] = $tookEffect;
    }

    /**
     * Every call throws as though the server were down — including the probe
     * the rename makes before it touches anything.
     */
    public function makeUnreachable(): void
    {
        $this->unreachable = true;
    }

    /**
     * @return list<string>
     */
    public function operations(): array
    {
        return $this->inner->operations();
    }

    public function createMailbox(MailboxIdentity $identity): void
    {
        $this->guardReachable();
        $this->guardOperation('createMailbox', $identity);

        if (($this->lostAnswers['createMailbox'] ?? true) === false) {
            $this->guardAnswer('createMailbox');
        }

        $this->inner->createMailbox($identity);
        $this->guardAnswer('createMailbox');
    }

    public function deleteMailbox(MailboxIdentity $identity): void
    {
        $this->guardReachable();
        $this->guardOperation('deleteMailbox', $identity);
        $this->inner->deleteMailbox($identity);
    }

    public function renameMailbox(MailboxIdentity $from, MailboxIdentity $to): void
    {
        $this->guardReachable();

        $key = self::key($from, $to);

        if (($this->failures[$key] ?? 0) > 0) {
            --$this->failures[$key];

            throw MailboxOperationFailed::userMoveRefused($from->imapMailbox(), $to->imapMailbox());
        }

        $this->inner->renameMailbox($from, $to);
    }

    public function mailboxExists(MailboxIdentity $identity): bool
    {
        $this->guardReachable();

        return $this->inner->mailboxExists($identity);
    }

    public function listMailboxes(string $pattern): iterable
    {
        $this->guardReachable();

        return $this->inner->listMailboxes($pattern);
    }

    public function getQuota(MailboxIdentity $identity): Quota
    {
        $this->guardReachable();
        $this->guardOperation('getQuota', $identity);

        return $this->inner->getQuota($identity);
    }

    public function setQuota(MailboxIdentity $identity, Quota $quota): void
    {
        $this->guardReachable();
        $this->guardOperation('setQuota', $identity);
        $this->inner->setQuota($identity, $quota);
    }

    public function getAcl(MailboxIdentity $identity): AclSet
    {
        $this->guardReachable();

        return $this->inner->getAcl($identity);
    }

    public function setAcl(MailboxIdentity $identity, string $subject, Acl $acl): void
    {
        $this->guardReachable();
        $this->guardOperation('setAcl', $identity);
        $this->inner->setAcl($identity, $subject, $acl);
    }

    public function createDefaultFolders(MailboxIdentity $identity, DefaultFolders $folders): void
    {
        $this->guardReachable();
        $this->guardOperation('createDefaultFolders', $identity);
        $this->inner->createDefaultFolders($identity, $folders);
    }

    private function guardReachable(): void
    {
        if ($this->unreachable) {
            throw ConnectionFailed::socket('mail.example.test:993', 'the test asked the server to be down');
        }
    }

    private function guardOperation(string $operation, MailboxIdentity $identity): void
    {
        if (($this->operationGrace[$operation] ?? 0) > 0) {
            --$this->operationGrace[$operation];

            return;
        }

        if (($this->operationFailures[$operation] ?? 0) > 0) {
            --$this->operationFailures[$operation];

            throw MailboxOperationFailed::deletionRefused($identity->imapMailbox(), 'the test asked ' . $operation . ' to fail');
        }
    }

    private function guardAnswer(string $operation): void
    {
        if (isset($this->lostAnswers[$operation])) {
            unset($this->lostAnswers[$operation]);

            throw ReadTimedOut::whileReading('mail.example.test:993', 30.0, $operation);
        }
    }

    private static function key(MailboxIdentity $from, MailboxIdentity $to): string
    {
        return $from->imapMailbox() . ' -> ' . $to->imapMailbox();
    }
}
