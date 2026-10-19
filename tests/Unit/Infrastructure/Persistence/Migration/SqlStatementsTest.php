<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Persistence\Migration;

use Mailadmin\Infrastructure\Persistence\Migration\SqlStatements;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqlStatements::class)]
final class SqlStatementsTest extends TestCase
{
    public function testSplitsOnSemicolons(): void
    {
        self::assertSame(
            ['SELECT 1', 'SELECT 2'],
            SqlStatements::split("SELECT 1;\nSELECT 2;\n"),
        );
    }

    public function testATrailingStatementWithoutASemicolonStillCounts(): void
    {
        self::assertSame(['SELECT 1'], SqlStatements::split('SELECT 1'));
    }

    public function testEmptyStatementsAreDropped(): void
    {
        self::assertSame(['SELECT 1'], SqlStatements::split(";;\nSELECT 1;\n;\n"));
    }

    public function testALineCommentIsRemoved(): void
    {
        self::assertSame(
            ['SELECT 1'],
            SqlStatements::split("-- a comment; with a semicolon\nSELECT 1;"),
        );
    }

    public function testAHashCommentIsRemoved(): void
    {
        self::assertSame(['SELECT 1'], SqlStatements::split("# phpMyAdmin style; comment\nSELECT 1;"));
    }

    public function testABlockCommentIsRemoved(): void
    {
        self::assertSame(['SELECT 1'], SqlStatements::split('/* hidden; text */ SELECT 1;'));
    }

    /**
     * The reason this is not a `explode(';', …)`: a `COMMENT` on a column is
     * ordinary in the migrations this project ships.
     */
    public function testASemicolonInsideAStringIsNotABoundary(): void
    {
        $statements = SqlStatements::split(
            "CREATE TABLE t (a INT COMMENT 'one; two') ; SELECT 1;",
        );

        self::assertCount(2, $statements);
        self::assertStringContainsString("'one; two'", $statements[0]);
    }

    public function testASemicolonInsideABackQuotedIdentifierIsNotABoundary(): void
    {
        $statements = SqlStatements::split('SELECT `weird;name` FROM t; SELECT 2;');

        self::assertCount(2, $statements);
    }

    public function testADoubledQuoteInsideAStringIsNotTheEnd(): void
    {
        $statements = SqlStatements::split("SELECT 'it''s; fine'; SELECT 2;");

        self::assertCount(2, $statements);
        self::assertStringContainsString("'it''s; fine'", $statements[0]);
    }

    public function testABackslashEscapedQuoteIsNotTheEnd(): void
    {
        $statements = SqlStatements::split("SELECT 'a\\'; b'; SELECT 2;");

        self::assertCount(2, $statements);
    }

    /**
     * A comment must not be able to smuggle anything past the rule check, which
     * reads the statements this class returns.
     */
    public function testACommentCannotHideAStatement(): void
    {
        $statements = SqlStatements::split('SELECT 1 /* ; DROP TABLE domain; */ ;');

        self::assertCount(1, $statements);
        self::assertStringNotContainsString('DROP', $statements[0]);
    }

    public function testAnEmptyFileYieldsNothing(): void
    {
        self::assertSame([], SqlStatements::split("\n-- only a comment\n"));
    }
}
