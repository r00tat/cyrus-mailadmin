<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\TestEnvironment;

use Mailadmin\Application\Address\RowWriter;
use Mailadmin\Application\MailingList\CreateMailingList;
use Mailadmin\Application\MailingList\DeleteMailingList;
use Mailadmin\Application\MailingList\ListDelivery;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\DomainRepository;
use Mailadmin\Infrastructure\Persistence\InMemoryAuditTrail;
use Mailadmin\Infrastructure\Persistence\OpenConnection;
use Mailadmin\Infrastructure\Persistence\SqlMailingListRepository;
use Mailadmin\Infrastructure\Persistence\VirtualRepository;
use Mailadmin\Infrastructure\Repair\InMemoryRepairQueue;
use Mailadmin\Tests\Fixtures\TestAccounts;
use Mailadmin\Tests\Support\DomainFixture;
use Mailadmin\Tests\Support\FrozenClock;
use Mailadmin\Tests\Support\ListDeliveryFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * List mail end to end through Postfix: a list created in transport mode
 * routes its address through `virtual` to the pseudo-domain, `transport_maps`
 * hands that to the `maillist` pipe transport, and the transport calls the
 * script with the list key.
 *
 * The script is a stub in F5 (docker/mail/maillist-stub.sh). It writes the key
 * and the message into a volume the app container can read, which is all this
 * test needs to know which key Postfix handed over.
 *
 * Every message carries a token of its own, so the test finds its entries in a
 * log that earlier runs have written to as well.
 *
 * Runs in `leer.test`, which holds no fixture addresses.
 */
#[CoversClass(CreateMailingList::class)]
#[CoversClass(DeleteMailingList::class)]
final class MailingListDeliveryTest extends TestCase
{
    use RequiresTestEnvironment;

    private const string LIST_ADDRESS = 'team@leer.test';
    private const string LIST_KEY = 'team.leer.test';
    private const string STUB_LOG_VARIABLE = 'MAILADMIN_TEST_MAILLIST_STUB_LOG';

    /** How long a message may take from submission to the stub. */
    private const float DELIVERY_TIMEOUT_SECONDS = 10.0;

    /**
     * How long to wait before concluding that a message did not reach the
     * stub. A delivered message arrives within a second here; this is a margin.
     */
    private const float ABSENCE_WAIT_SECONDS = 4.0;

    private ?Connection $connection = null;
    private bool $ready = false;

    protected function setUp(): void
    {
        $this->requireFixtures();

        if (filter_var(TestEnvironment::mailHost(), FILTER_VALIDATE_IP) !== false || TestEnvironment::caFile() === null) {
            self::markTestSkipped('Certificate verification needs the environment’s host name and CA: run `bin/test-env test`.');
        }

        if (!is_dir(dirname(self::stubLog()))) {
            self::markTestSkipped(sprintf(
                'The log of the maillist stub is not visible here (%s). Run `bin/test-env test`.',
                self::stubLog(),
            ));
        }

        $this->ready = true;
        $this->removeEverything();
    }

    protected function tearDown(): void
    {
        if ($this->ready) {
            $this->removeEverything();
        }

        $this->ready = false;
    }

    public function testListMailReachesTheTransportWithTheListKeyAndStopsAfterDeletion(): void
    {
        $this->createList();

        $plain = self::token();
        self::assertSame(250, self::submit(self::LIST_ADDRESS, $plain), 'Postfix refused mail to the list.');
        self::assertSame(
            self::LIST_KEY,
            self::awaitKey($plain),
            'The pipe transport did not receive the list mail with the list key.',
        );

        // `recipient_delimiter = +`: `team+x` resolves through the `virtual`
        // row of `team`, and `propagate_unmatched_extensions` carries `+x` over
        // to `team.leer.test+x@maillist.invalid`. `${user}` drops it again.
        $extended = self::token();
        self::assertSame(250, self::submit('team+x@leer.test', $extended), 'Postfix refused mail to the list with an extension.');
        self::assertSame(
            self::LIST_KEY,
            self::awaitKey($extended),
            'Mail to team+x did not reach the transport with the bare list key. The transport must pass ${user}, not ${mailbox}.',
        );

        $this->deleteList();

        // The `virtual` row is gone, so the address is an ordinary local
        // recipient of leer.test again. F5 accepts every recipient in a local
        // domain (`local_recipient_maps =`) and leaves the verdict to Cyrus, so
        // the message is accepted and then goes to LMTP, not to the transport.
        // Neither a reject nor a bounce in F5: Postfix cannot open Cyrus's LMTP
        // socket here, so it stays deferred (see AccountLifecycleTest). Against
        // a working LMTP, Cyrus would refuse the unknown mailbox and Postfix
        // would bounce it.
        $afterDeletion = self::token();
        self::assertSame(
            250,
            self::submit(self::LIST_ADDRESS, $afterDeletion),
            'F5 accepts every recipient in a local domain; a refusal here means local_recipient_maps changed.',
        );
        self::assertNull(
            self::keyAfterWaiting($afterDeletion),
            'Mail to a deleted list still reached the pipe transport.',
        );
    }

    /**
     * An authenticated sender addressing the pseudo-domain directly would reach
     * any list past mailadmin's sender checks. `maillist_block` refuses it at
     * RCPT TO, before `virtual` is consulted.
     */
    public function testDirectMailToTheTransportDomainIsRefused(): void
    {
        $token = self::token();
        $code = self::submit(self::LIST_KEY . '@' . ListDelivery::DEFAULT_TRANSPORT_DOMAIN, $token);

        self::assertGreaterThanOrEqual(500, $code, 'Postfix accepted mail addressed to the transport domain directly.');
        self::assertLessThan(600, $code);
        self::assertNull(self::keyAfterWaiting($token), 'Mail addressed to the transport domain reached the pipe transport.');
    }

    private function createList(): void
    {
        $audit = new InMemoryAuditTrail();
        $repairs = new InMemoryRepairQueue();
        $virtual = new VirtualRepository($this->connection());

        (new CreateMailingList(
            new SqlMailingListRepository($this->connection()),
            $virtual,
            new DomainRepository($this->connection(), new FrozenClock()),
            ListDeliveryFixture::delivery(),
            $audit,
            new RowWriter($virtual, $audit, $repairs, new NullLogger()),
            $repairs,
            new NullLogger(),
        ))->handle(DomainFixture::superuser(), 'team-leer', 'team', self::LIST_ADDRESS, [TestAccounts::bcryptAccount()->address]);

        self::assertSame(
            ListDeliveryFixture::delivery()->destinationFor(EmailAddress::parse(self::LIST_ADDRESS)),
            $this->connection()->selectValue('SELECT dest FROM virtual WHERE alias = :a', ['a' => self::LIST_ADDRESS]),
        );
        self::assertTrue($repairs->isEmpty());
    }

    private function deleteList(): void
    {
        $audit = new InMemoryAuditTrail();
        $repairs = new InMemoryRepairQueue();
        $virtual = new VirtualRepository($this->connection());
        $lists = new SqlMailingListRepository($this->connection());

        (new DeleteMailingList(
            $lists,
            ListDeliveryFixture::reader($virtual, $lists),
            new DomainRepository($this->connection(), new FrozenClock()),
            $virtual,
            $audit,
            new RowWriter($virtual, $audit, $repairs, new NullLogger()),
            $repairs,
            new NullLogger(),
        ))->handle(DomainFixture::superuser(), self::LIST_ADDRESS, null);

        self::assertSame(
            0,
            (int) $this->connection()->selectValue('SELECT COUNT(*) FROM virtual WHERE alias = :a', ['a' => self::LIST_ADDRESS]),
        );
        self::assertTrue($repairs->isEmpty());
    }

    /**
     * Submits one message as Anna and returns the answer to RCPT TO. The
     * message is only sent if the recipient was accepted.
     */
    private static function submit(string $recipient, string $token): int
    {
        $anna = TestAccounts::bcryptAccount();
        $smtp = SmtpProbe::connect();

        try {
            $smtp->ehlo();
            $smtp->startTls();
            $smtp->ehlo();
            self::assertSame(235, $smtp->authPlain($anna->storedUsername, $anna->password));

            self::assertSame(250, $smtp->command(sprintf('MAIL FROM:<%s>', $anna->address))[0]);
            [$code] = $smtp->command(sprintf('RCPT TO:<%s>', $recipient));

            if ($code !== 250) {
                $smtp->command('RSET');

                return $code;
            }

            self::assertSame(354, $smtp->command('DATA')[0]);
            self::assertSame(250, $smtp->command(implode("\r\n", [
                sprintf('From: <%s>', $anna->address),
                sprintf('To: <%s>', $recipient),
                'Subject: list delivery probe ' . $token,
                '',
                'Token: ' . $token,
                '.',
            ]))[0]);

            return $code;
        } finally {
            $smtp->close();
        }
    }

    /** The key of the stub entry carrying `$token`, waiting up to the delivery timeout. */
    private static function awaitKey(string $token): ?string
    {
        $deadline = microtime(true) + self::DELIVERY_TIMEOUT_SECONDS;

        do {
            $key = self::keyFor($token);

            if ($key !== null) {
                return $key;
            }

            usleep(200_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /** The key of the stub entry carrying `$token` after the absence margin, normally none. */
    private static function keyAfterWaiting(string $token): ?string
    {
        usleep((int) (self::ABSENCE_WAIT_SECONDS * 1_000_000));

        return self::keyFor($token);
    }

    private static function keyFor(string $token): ?string
    {
        clearstatcache();

        $log = @file_get_contents(self::stubLog());

        if ($log === false) {
            return null;
        }

        foreach (explode("\n--end--\n", $log) as $entry) {
            if (!str_contains($entry, $token)) {
                continue;
            }

            if (preg_match('/^key=(.*)$/m', $entry, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    private static function token(): string
    {
        return 'probe-' . bin2hex(random_bytes(8));
    }

    private static function stubLog(): string
    {
        $configured = getenv(self::STUB_LOG_VARIABLE);

        return $configured === false || $configured === '' ? '/var/log/maillist-stub/calls.log' : $configured;
    }

    private function connection(): Connection
    {
        return $this->connection ??= new Connection(new OpenConnection(TestEnvironment::connection()));
    }

    private function removeEverything(): void
    {
        $this->connection()->execute('DELETE FROM virtual WHERE alias = :a', ['a' => self::LIST_ADDRESS]);
        $this->connection()->execute('DELETE FROM lists WHERE address = :a', ['a' => self::LIST_ADDRESS]);
    }
}
