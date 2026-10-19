<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Deployment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the web root protection from U-25 complete.
 *
 * The protection is a set of files, not code, so nothing else would notice a gap.
 * A later package that adds a top-level directory — frontend/ in W1, for instance —
 * fails here instead of shipping an unprotected directory into the web root.
 */
final class HtaccessProtectionTest extends TestCase
{
    /**
     * Directories that hold no project files and are therefore not covered:
     * tooling state, the git database, and the reference copy of the predecessor.
     */
    private const array IGNORED_DIRECTORIES = [
        '.git',
        '.github',
        '.idea',
        '.vscode',
        '.worktrees',
        '.claude',
        'webcyradm',
    ];

    /**
     * Not tracked in git, so a file placed inside them would be removed by the
     * next `composer install` / `npm install`. The root .htaccess covers them by
     * name instead; testTheRootRuleNamesEveryProtectedDirectory checks that.
     */
    private const array UNTRACKED_DIRECTORIES = [
        'vendor',
        'node_modules',
    ];

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return list<string>
     */
    private static function topLevelDirectories(): array
    {
        $entries = scandir(self::projectRoot());
        self::assertIsArray($entries, 'The project root is not readable.');

        $directories = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'public') {
                continue;
            }

            if (in_array($entry, self::IGNORED_DIRECTORIES, true)) {
                continue;
            }

            if (!is_dir(self::projectRoot() . '/' . $entry)) {
                continue;
            }

            $directories[] = $entry;
        }

        return $directories;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function trackedDirectoryProvider(): iterable
    {
        foreach (self::topLevelDirectories() as $directory) {
            if (in_array($directory, self::UNTRACKED_DIRECTORIES, true)) {
                continue;
            }

            yield $directory => [$directory];
        }
    }

    #[DataProvider('trackedDirectoryProvider')]
    public function testEveryNonPublicDirectoryDeniesAccess(string $directory): void
    {
        $file = self::projectRoot() . '/' . $directory . '/.htaccess';

        self::assertFileExists($file, sprintf(
            'Directory "%s/" would be readable when the project is deployed inside the web '
            . 'root (U-25). Copy src/.htaccess into it.',
            $directory,
        ));

        $contents = (string) file_get_contents($file);

        self::assertStringContainsString('Require all denied', $contents, sprintf(
            '%s/.htaccess exists but does not deny access.',
            $directory,
        ));
        self::assertStringContainsString('Deny from all', $contents, sprintf(
            '%s/.htaccess has no fallback for servers without mod_authz_core.',
            $directory,
        ));
    }

    public function testTheRootRuleNamesEveryProtectedDirectory(): void
    {
        $root = (string) file_get_contents(self::projectRoot() . '/.htaccess');

        $matched = preg_match('/RewriteRule \^\(([a-z_|]+)\)\(\/\|\$\) - \[F,L\]/', $root, $match);

        self::assertSame(1, $matched, 'The root .htaccess has no directory deny rule (U-25, layer 1).');

        $listed = isset($match[1]) ? explode('|', $match[1]) : [];

        self::assertNotSame([], $listed, 'The deny rule names no directory at all.');

        // Every tracked directory, plus the untracked ones that layer 2 cannot reach.
        $expected = array_merge(self::topLevelDirectories(), self::UNTRACKED_DIRECTORIES);

        foreach (array_unique($expected) as $directory) {
            self::assertContains($directory, $listed, sprintf(
                'The root .htaccess rewrite rule does not name "%s". Both lists must stay in sync.',
                $directory,
            ));
        }
    }

    public function testPublicGrantsAccessAndRoutesTheApi(): void
    {
        $file = self::projectRoot() . '/public/.htaccess';

        self::assertFileExists($file);

        $contents = (string) file_get_contents($file);

        self::assertStringContainsString('Require all granted', $contents);
        self::assertStringContainsString('index.php', $contents, 'The front controller is not routed.');
        self::assertStringNotContainsString('Require all denied', $contents);
    }

    public function testTheRootFileDeniesDotfiles(): void
    {
        $root = (string) file_get_contents(self::projectRoot() . '/.htaccess');

        // .env is the one that would hurt: in development it holds credentials.
        self::assertStringContainsString('<FilesMatch "^\\.">', $root);
        self::assertStringContainsString('Options -Indexes', $root);
    }
}
