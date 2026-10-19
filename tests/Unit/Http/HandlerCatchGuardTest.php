<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Http;

use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\Policy\AccessDenied;
use Mailadmin\Domain\Policy\ResourceNotVisible;
use PhpToken;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * No handler may catch a policy refusal (U-98).
 *
 * `AccessDenied` and `ResourceNotVisible` travel from the use case to
 * `AuthorizationMiddleware`, which turns them into `403` and the `404` that
 * hides a foreign object. A handler that catches them on the way — directly, or
 * through a parent type such as `DomainError`, `Exception` or `Throwable` —
 * answers something else, typically a `422` that names the field and thereby
 * confirms that the object exists.
 *
 * The test reads every handler's `catch` clauses, resolves the names through
 * the file's `use` statements and asks PHP whether either refusal is an instance
 * of the caught type. It is a static check on purpose: a behavioural test per
 * handler would only cover the paths somebody thought of.
 */
#[CoversNothing]
final class HandlerCatchGuardTest extends TestCase
{
    private const string HANDLERS = __DIR__ . '/../../../src/Http/Handler';

    /**
     * Catches that are known to wrap no use-case call, with the reason.
     *
     * @var array<string, list<string>> path relative to the handler directory => caught types
     */
    private const array EXEMPT = [
        // `toColumnValue()` wraps `new DateTimeImmutable()` and nothing else.
        'Audit/ListAuditEntriesHandler.php' => ['Exception'],
    ];

    public function testThePolicyRefusalsAreNotDomainErrors(): void
    {
        // The structural half of the fix: a `catch (DomainError)` that turns a
        // value the model refused into a `422` cannot reach them at all.
        foreach ([AccessDenied::class, ResourceNotVisible::class] as $refusal) {
            self::assertNotContains(DomainError::class, (array) class_parents($refusal), $refusal);
        }
    }

    public function testNoHandlerCatchesAPolicyRefusal(): void
    {
        $violations = [];
        $clauses = 0;

        foreach (self::handlerFiles() as $relative => $path) {
            foreach (self::caughtTypes($path) as [$line, $type]) {
                ++$clauses;

                if (!self::swallowsPolicyRefusal($type)) {
                    continue;
                }

                $short = substr($type, (int) strrpos('\\' . $type, '\\'));

                if (in_array($short, self::EXEMPT[$relative] ?? [], true)) {
                    continue;
                }

                $violations[] = sprintf('%s:%d catches %s', $relative, $line, $type);
            }
        }

        self::assertGreaterThan(20, $clauses, 'The scan found too few catch clauses to mean anything.');
        self::assertSame([], $violations, 'A handler would turn a 403/404 into another answer.');
    }

    private static function swallowsPolicyRefusal(string $type): bool
    {
        return is_a(AccessDenied::class, $type, true) || is_a(ResourceNotVisible::class, $type, true);
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    private static function handlerFiles(): array
    {
        $base = realpath(self::HANDLERS);
        self::assertIsString($base);

        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[substr($file->getPathname(), strlen($base) + 1)] = $file->getPathname();
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * Every type named in a `catch`, fully qualified.
     *
     * @return list<array{int, string}> line => type
     */
    private static function caughtTypes(string $path): array
    {
        $source = file_get_contents($path);
        self::assertIsString($source);

        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $token): bool => !$token->isIgnorable(),
        ));

        $namespace = '';
        $imports = [];
        $caught = [];

        foreach ($tokens as $index => $token) {
            if ($token->is(T_NAMESPACE)) {
                $namespace = $tokens[$index + 1]->text;
            }

            // Top-level `use` only: a closure's `use (...)` is followed by `(`.
            if ($token->is(T_USE) && $tokens[$index + 1]->is([T_NAME_QUALIFIED, T_STRING, T_NAME_FULLY_QUALIFIED])) {
                $name = ltrim($tokens[$index + 1]->text, '\\');
                $alias = substr($name, (int) strrpos('\\' . $name, '\\'));

                if ($tokens[$index + 2]->is(T_AS)) {
                    $alias = $tokens[$index + 3]->text;
                }

                $imports[$alias] = $name;
            }

            if (!$token->is(T_CATCH)) {
                continue;
            }

            for ($i = $index + 2; isset($tokens[$i]) && !$tokens[$i]->is([T_VARIABLE, ')']); ++$i) {
                if ($tokens[$i]->is('|')) {
                    continue;
                }

                $caught[] = [$token->line, self::resolve($tokens[$i]->text, $namespace, $imports)];
            }
        }

        return $caught;
    }

    /**
     * @param array<string, string> $imports
     */
    private static function resolve(string $name, string $namespace, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $first = explode('\\', $name)[0];

        if (isset($imports[$first])) {
            return $imports[$first] . substr($name, strlen($first));
        }

        $local = $namespace . '\\' . $name;

        // An unqualified global class (`Throwable`, `Exception`) is written
        // without a backslash in a file that imports nothing by that name.
        return class_exists($local) || interface_exists($local) ? $local : $name;
    }
}
