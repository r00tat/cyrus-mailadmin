<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Contract;

use Mailadmin\Http\Problem\ErrorCode;
use Mailadmin\Http\Routing\ContractLinter;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The rules every package that extends the contract has to follow.
 *
 * These are not tests of F2's code — they are tests of the contract itself, and
 * they stay useful precisely because eight more packages will edit it. A
 * convention that is only written down in `openapi/README.md` is a convention
 * that erodes.
 */
#[CoversNothing]
final class ContractConventionsTest extends ContractTestCase
{
    public function testTheBundledContractValidatesAgainstOpenApiThreeZero(): void
    {
        ContractLinter::assertValid(self::contract());

        $this->addToAssertionCount(1);
    }

    public function testTheContractIsThreeZeroPointX(): void
    {
        // U-2. Moving to 3.1 means changing the validator, not just this line.
        self::assertMatchesRegularExpression('/^3\.0\./', (string) self::contract()['openapi']);
    }

    public function testTheVersionIsInThePathAndNowhereElse(): void
    {
        // U-10: a future v2 runs beside v1, so the prefix belongs to `servers`.
        self::assertSame('/api/v1', self::routeTable()->basePath);

        foreach (array_keys(self::contract()['paths']) as $path) {
            self::assertStringStartsNotWith('/api/', (string) $path, 'A path repeats the version prefix.');
        }
    }

    public function testEveryOperationDeclaresAnOperationIdATagAndAPermission(): void
    {
        foreach (self::operations() as $where => $operation) {
            self::assertIsString($operation['operationId'] ?? null, $where . ' has no operationId.');
            self::assertNotEmpty($operation['tags'] ?? [], $where . ' has no tags.');
            self::assertIsString($operation['x-permission'] ?? null, $where . ' has no x-permission.');
        }
    }

    public function testEveryOperationIdIsUniqueAndLowerCamelCase(): void
    {
        $seen = [];

        foreach (self::operations() as $where => $operation) {
            $id = (string) $operation['operationId'];

            self::assertMatchesRegularExpression('/^[a-z][A-Za-z0-9]*$/', $id, $where);
            self::assertArrayNotHasKey($id, $seen, sprintf('operationId "%s" is used twice.', $id));

            $seen[$id] = $where;
        }
    }

    public function testEveryTagAnOperationUsesIsDeclaredWithADescription(): void
    {
        $declared = [];

        /** @var mixed $tag */
        foreach (self::contract()['tags'] ?? [] as $tag) {
            if (is_array($tag) && is_string($tag['name'] ?? null)) {
                $declared[$tag['name']] = $tag['description'] ?? null;
            }
        }

        foreach (self::operations() as $where => $operation) {
            /** @var mixed $name */
            foreach ((array) ($operation['tags'] ?? []) as $name) {
                self::assertArrayHasKey($name, $declared, $where . ' uses an undeclared tag.');
                self::assertIsString($declared[$name], sprintf('Tag "%s" has no description.', (string) $name));
            }
        }
    }

    public function testEveryOperationCanFailWithFiveHundred(): void
    {
        foreach (self::operations() as $where => $operation) {
            /** @var array<array-key, mixed> $responses */
            $responses = $operation['responses'] ?? [];

            self::assertArrayHasKey(500, $responses, $where . ' does not declare a 500 response.');
        }
    }

    public function testNoOperationUsesADefaultResponse(): void
    {
        // A `default` response would swallow an undeclared status code, and
        // response validation (U-9) would then never notice it.
        foreach (self::operations() as $where => $operation) {
            /** @var array<array-key, mixed> $responses */
            $responses = $operation['responses'] ?? [];

            self::assertArrayNotHasKey('default', $responses, $where . ' uses a `default` response.');
        }
    }

    public function testEveryErrorResponseIsAProblemDocument(): void
    {
        /** @var array<string, mixed> $responses */
        $responses = self::contract()['components']['responses'] ?? [];

        self::assertNotEmpty($responses);

        /** @var mixed $response */
        foreach ($responses as $name => $response) {
            self::assertIsArray($response);
            /** @var array<string, mixed> $content */
            $content = $response['content'] ?? [];

            self::assertArrayHasKey('application/problem+json', $content, $name . ' is not a problem document.');
            self::assertSame(
                '#/components/schemas/Problem',
                $content['application/problem+json']['schema']['$ref'] ?? null,
                $name . ' does not reuse the Problem schema.',
            );
        }
    }

    public function testTheProblemSchemaRequiresTheStableCodeField(): void
    {
        /** @var array<string, mixed> $problem */
        $problem = self::contract()['components']['schemas']['Problem'];

        // E-16: the code is the contract, `title` and `detail` are fallbacks.
        self::assertContains('code', $problem['required']);
        self::assertSame(
            '#/components/schemas/ErrorCode',
            $problem['properties']['code']['$ref'] ?? null,
        );
    }

    public function testEveryErrorCodeFollowsResourceDotState(): void
    {
        foreach (self::errorCodes() as $code) {
            self::assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/',
                $code,
                sprintf('"%s" is not <resource>.<state> in snake_case (U-7).', $code),
            );
        }
    }

    public function testTheErrorCodeEnumeratesTheResourcePrefixesUSevenAllows(): void
    {
        $allowed = [
            'auth', 'domain', 'account', 'address', 'alias', 'catchall', 'forward',
            'vacation', 'admin', 'list', 'quota', 'mailstore', 'sieve', 'migration', 'request',
            // A6 extends U-7's list with the repair queue's own prefix (U-122).
            'repair',
        ];

        foreach (self::errorCodes() as $code) {
            self::assertContains(explode('.', $code)[0], $allowed, sprintf('"%s" invents a prefix.', $code));
        }
    }

    public function testTheErrorCodesAreSortedWithinTheirBlock(): void
    {
        $codes = self::errorCodes();
        $byPrefix = [];

        foreach ($codes as $code) {
            $byPrefix[explode('.', $code)[0]][] = $code;
        }

        foreach ($byPrefix as $prefix => $block) {
            $sorted = $block;
            sort($sorted);

            self::assertSame($sorted, $block, sprintf('The "%s" block is not in alphabetical order.', $prefix));
        }
    }

    public function testEveryCodeTheFrameworkCanProduceIsInTheContract(): void
    {
        // The middleware chain runs outside the contract, so the constants in
        // ErrorCode and the enum here are two lists. They must be one set.
        foreach (ErrorCode::all() as $code) {
            self::assertContains(
                $code,
                self::errorCodes(),
                sprintf('ErrorCode::%s is not in the contract enumeration.', $code),
            );
        }
    }

    public function testPaginationIsFixedAtFiftyByDefaultAndTwoHundredAtMost(): void
    {
        /** @var array<string, mixed> $parameters */
        $parameters = self::contract()['components']['parameters'];

        self::assertSame(1, $parameters['Page']['schema']['default']);
        self::assertSame(1, $parameters['Page']['schema']['minimum']);
        self::assertSame(50, $parameters['PerPage']['schema']['default']);
        self::assertSame(200, $parameters['PerPage']['schema']['maximum']);
    }

    public function testTheReusableEtagAndIfMatchComponentsExist(): void
    {
        // U-19: concurrency control is a shared component, not something each
        // package reinvents.
        self::assertArrayHasKey('IfMatch', self::contract()['components']['parameters']);
        self::assertSame('If-Match', self::contract()['components']['parameters']['IfMatch']['name']);
        self::assertArrayHasKey('ETag', self::contract()['components']['headers']);
    }

    public function testTheBearerSchemeIsDeclaredAndRequiredByDefault(): void
    {
        /** @var array<string, mixed> $schemes */
        $schemes = self::contract()['components']['securitySchemes'];

        self::assertSame('http', $schemes['bearerAuth']['type']);
        self::assertSame('bearer', $schemes['bearerAuth']['scheme']);
        self::assertSame([['bearerAuth' => []]], self::contract()['security']);
    }

    public function testOnlyAnOperationThatSaysSoIsPublic(): void
    {
        foreach (self::operations() as $where => $operation) {
            if (!array_key_exists('security', $operation)) {
                continue;
            }

            self::assertSame([], $operation['security'], $where . ' overrides security with something other than [].');
            self::assertSame('public', $operation['x-permission'] ?? null, $where . ' is public but not marked so.');
        }
    }

    public function testEveryContractPathHasARouteAndEveryRouteAContractPath(): void
    {
        $fromContract = array_keys(self::operations());
        $fromTable = array_map(
            static fn (object $route): string => (string) $route->method . ' ' . (string) $route->specPath,
            self::routeTable()->routes,
        );

        sort($fromContract);
        sort($fromTable);

        self::assertSame($fromContract, $fromTable);
    }

    /**
     * @return list<string>
     */
    private static function errorCodes(): array
    {
        /** @var array<array-key, mixed> $enum */
        $enum = self::contract()['components']['schemas']['ErrorCode']['enum'];

        $codes = [];

        /** @var mixed $code */
        foreach ($enum as $code) {
            self::assertIsString($code);
            $codes[] = $code;
        }

        return $codes;
    }
}
