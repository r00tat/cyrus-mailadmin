<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Security;

use Mailadmin\Domain\Password\PasswordPolicy;
use Mailadmin\Domain\Password\PasswordSubject;
use Mailadmin\Infrastructure\Security\FileCommonPasswordList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The block list of U-21 — the reader, and the file that ships with it.
 */
#[CoversClass(FileCommonPasswordList::class)]
final class FileCommonPasswordListTest extends TestCase
{
    private static function shippedFile(): string
    {
        return dirname(__DIR__, 4) . '/resources/common-passwords.txt';
    }

    private static function shippedList(): FileCommonPasswordList
    {
        return new FileCommonPasswordList(self::shippedFile());
    }

    // ------------------------------------------------------------------
    // The reader
    // ------------------------------------------------------------------

    public function testItReadsEntriesAndIgnoresCommentsAndBlankLines(): void
    {
        $list = new FileCommonPasswordList($this->fixture([
            '# a comment',
            '',
            'trombone-picture',
            'sonnenaufgangimmai',
            '   ',
        ]));

        self::assertTrue($list->contains('trombone-picture'));
        self::assertTrue($list->contains('sonnenaufgangimmai'));
        self::assertFalse($list->contains('# a comment'));
        self::assertFalse($list->contains(''));
        self::assertSame(3, $list->count(), 'The whitespace-only line is an entry of its own.');
    }

    public function testTheLookupIsCaseInsensitive(): void
    {
        $list = new FileCommonPasswordList($this->fixture(['trombone-picture']));

        self::assertTrue($list->contains('TROMBONE-PICTURE'));
        self::assertTrue($list->contains('Trombone-Picture'));
    }

    public function testANumericEntryIsFound(): void
    {
        $list = new FileCommonPasswordList($this->fixture(['1234567890']));

        self::assertTrue($list->contains('1234567890'));
    }

    public function testCarriageReturnsAreStripped(): void
    {
        $path = $this->fixture([]);
        file_put_contents($path, "trombone-picture\r\nsonnenaufgangimmai\r\n");

        $list = new FileCommonPasswordList($path);

        self::assertTrue($list->contains('trombone-picture'));
        self::assertTrue($list->contains('sonnenaufgangimmai'));
    }

    /**
     * A block list that quietly disappears is worse than no block list: every
     * common password would be accepted and nothing downstream would notice.
     */
    public function testAMissingFileIsAnErrorAndNotAnEmptyList(): void
    {
        $list = new FileCommonPasswordList('/does/not/exist/common-passwords.txt');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/block list/');

        $list->contains('anything');
    }

    public function testTheFileIsReadOnlyOnce(): void
    {
        $path = $this->fixture(['trombone-picture']);
        $list = new FileCommonPasswordList($path);

        self::assertTrue($list->contains('trombone-picture'));

        unlink($path);

        self::assertTrue($list->contains('trombone-picture'), 'The list should be cached after the first read.');
    }

    // ------------------------------------------------------------------
    // The shipped file
    // ------------------------------------------------------------------

    public function testTheShippedFileExistsAndIsSubstantial(): void
    {
        self::assertFileExists(self::shippedFile());
        self::assertGreaterThan(
            10000,
            self::shippedList()->count(),
            'U-21 asks for the order of ten thousand common passwords.',
        );
    }

    /**
     * The entries that can actually be reached: everything shorter than ten
     * characters is already refused by the length rule, so a list made only of
     * those would be decoration.
     */
    public function testTheShippedFileHoldsEnoughEntriesLongEnoughToBeReachable(): void
    {
        $reachable = 0;

        foreach ($this->shippedEntries() as $entry) {
            if (strlen($entry) >= PasswordSubject::AccountUser->minimumLength()) {
                ++$reachable;
            }
        }

        self::assertGreaterThan(
            2000,
            $reachable,
            'Almost every entry is shorter than the minimum length and can never match.',
        );
    }

    #[DataProvider('passwordsThatMustBeBlocked')]
    public function testTheShippedFileBlocksTheObviousLongOnes(string $candidate): void
    {
        self::assertTrue(
            self::shippedList()->contains($candidate),
            sprintf('"%s" is long enough to pass the length rule and must be on the block list.', $candidate),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function passwordsThatMustBeBlocked(): iterable
    {
        yield 'password123' => ['password123'];
        yield 'qwertyuiop' => ['qwertyuiop'];
        yield '1234567890' => ['1234567890'];
        yield 'iloveyou123' => ['iloveyou123'];
        yield 'upper case spelling' => ['Password123'];
    }

    public function testTheShippedFileIsEntirelyLowerCaseSoTheLookupWorks(): void
    {
        $offenders = array_filter(
            $this->shippedEntries(),
            static fn (string $entry): bool => $entry !== strtolower($entry),
        );

        self::assertSame([], array_values($offenders), 'These entries are not lower case.');
    }

    /**
     * `#` starts a comment in this file. An entry beginning with one would be
     * read as a comment and silently dropped.
     */
    public function testNoEntryLooksLikeAComment(): void
    {
        $offenders = array_filter(
            $this->shippedEntries(),
            static fn (string $entry): bool => str_starts_with($entry, '#'),
        );

        self::assertSame([], array_values($offenders), 'These entries would be read as comments.');
    }

    public function testTheFileNamesWhereItCameFrom(): void
    {
        $header = (string) file_get_contents(self::shippedFile(), false, null, 0, 2048);

        self::assertStringContainsString('SecLists', $header);
        self::assertStringContainsString('MIT License', $header);
    }

    /**
     * The whole point: the policy refuses a common password when it is wired to
     * the shipped file, not only to a fixture.
     */
    public function testThePolicyRefusesACommonPasswordWhenWiredToTheShippedFile(): void
    {
        $policy = new PasswordPolicy(self::shippedList());

        self::assertFalse($policy->isAcceptable('password123', PasswordSubject::AccountUser));
        self::assertTrue($policy->isAcceptable('Untypischer-Sonnenschirm-42', PasswordSubject::AccountUser));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function shippedEntries(): array
    {
        $lines = file(self::shippedFile(), FILE_IGNORE_NEW_LINES);

        self::assertIsArray($lines);

        $entries = [];

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $entries[] = $line;
        }

        return $entries;
    }

    /**
     * @param list<string> $lines
     */
    private function fixture(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mailadmin-blocklist-');

        self::assertIsString($path);

        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }
}
