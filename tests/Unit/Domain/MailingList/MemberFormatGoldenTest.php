<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Domain\MailingList;

use Mailadmin\Domain\MailingList\MemberList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The byte-exact contract with the delivery script: the same files are read by
 * `maillist/tests/test_member_format.py`, so a change here that is not made
 * there (or the other way round) fails one of the two suites.
 */
#[CoversClass(MemberList::class)]
final class MemberFormatGoldenTest extends TestCase
{
    private const string DIR = __DIR__ . '/../../../Fixtures/MemberFormat';

    /**
     * @return iterable<string, array{string}>
     */
    public static function cases(): iterable
    {
        foreach (glob(self::DIR . '/*.members') ?: [] as $file) {
            $case = basename($file, '.members');

            yield $case => [$case];
        }
    }

    public function testCanonicalFileIsWhatMemberListWrites(): void
    {
        $written = MemberList::none()
            ->withMembers(['Alice Example <alice@example.org>', 'bob@example.org'])
            ->toStoredValue();

        self::assertSame(file_get_contents(self::DIR . '/canonical.members'), $written);
    }

    #[DataProvider('cases')]
    public function testStoredValueParsesToTheExpectedAddresses(string $case): void
    {
        $stored = (string) file_get_contents(self::DIR . "/{$case}.members");
        $expected = file(self::DIR . "/{$case}.recipients", FILE_IGNORE_NEW_LINES) ?: [];

        self::assertSame($expected, MemberList::fromStoredValue($stored)->deliverableAddresses());
    }
}
