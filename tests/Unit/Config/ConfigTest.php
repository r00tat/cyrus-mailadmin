<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Config;

use Mailadmin\Config\Config;
use Mailadmin\Config\ConfigurationError;
use Mailadmin\Config\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Config::class)]
#[CoversClass(ConfigurationError::class)]
#[CoversClass(Environment::class)]
final class ConfigTest extends TestCase
{
    public function testMissingMandatoryVariableFailsWithTheVariableNameInTheMessage(): void
    {
        $config = Config::fromArray([]);

        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('MAILADMIN_ENV');

        $config->environment();
    }

    public function testTheMessageForAMissingVariablePointsAtTheExampleFile(): void
    {
        try {
            Config::fromArray([])->string('MAILADMIN_SOMETHING');
            self::fail('Expected a ConfigurationError.');
        } catch (ConfigurationError $error) {
            self::assertStringContainsString('.env.example', $error->getMessage());
        }
    }

    public function testAnEmptyValueCountsAsNotSet(): void
    {
        // Deployment tooling regularly exports empty variables. A log target
        // named "" would be worse than the default.
        $config = Config::fromArray(['MAILADMIN_LOG_TARGET' => '   ']);

        self::assertFalse($config->has('MAILADMIN_LOG_TARGET'));
        self::assertSame('stderr', $config->logTarget());
    }

    public function testValuesAreTrimmed(): void
    {
        $config = Config::fromArray(['MAILADMIN_ENV' => " prod\n"]);

        self::assertSame(Environment::Production, $config->environment());
    }

    public function testNonScalarValuesAreIgnored(): void
    {
        $config = Config::fromArray(['MAILADMIN_ENV' => ['dev']]);

        self::assertFalse($config->has('MAILADMIN_ENV'));
    }

    public function testDefaultsApplyWhenTheVariableIsAbsent(): void
    {
        $config = Config::fromArray(['MAILADMIN_ENV' => 'dev']);

        self::assertSame('stderr', $config->logTarget());
        self::assertSame('info', $config->logLevel());
        self::assertSame('', $config->basePath());
        self::assertSame('/fallback/cache', $config->cacheDirectory('/fallback/cache'));
    }

    public function testConfiguredValuesWinOverDefaults(): void
    {
        $config = Config::fromArray([
            'MAILADMIN_ENV' => 'prod',
            'MAILADMIN_LOG_TARGET' => '/var/log/mailadmin/app.log',
            'MAILADMIN_LOG_LEVEL' => 'warning',
            'MAILADMIN_CACHE_DIR' => '/var/lib/mailadmin/cache/',
        ]);

        self::assertSame('/var/log/mailadmin/app.log', $config->logTarget());
        self::assertSame('warning', $config->logLevel());
        self::assertSame('/var/lib/mailadmin/cache', $config->cacheDirectory('/ignored'));
    }

    #[DataProvider('basePaths')]
    public function testTheBasePathIsNormalised(string $configured, string $expected): void
    {
        $config = Config::fromArray(['MAILADMIN_BASE_PATH' => $configured]);

        self::assertSame($expected, $config->basePath());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function basePaths(): iterable
    {
        yield 'no slashes' => ['mailadmin', '/mailadmin'];
        yield 'leading slash' => ['/mailadmin', '/mailadmin'];
        yield 'trailing slash' => ['mailadmin/', '/mailadmin'];
        yield 'both slashes' => ['/mailadmin/', '/mailadmin'];
        yield 'root' => ['/', ''];
        yield 'nested' => ['/tools/mailadmin/', '/tools/mailadmin'];
    }

    #[DataProvider('environments')]
    public function testTheEnvironmentIsParsed(string $value, Environment $expected): void
    {
        $config = Config::fromArray(['MAILADMIN_ENV' => $value]);

        self::assertSame($expected, $config->environment());
    }

    /**
     * @return iterable<string, array{string, Environment}>
     */
    public static function environments(): iterable
    {
        yield 'dev' => ['dev', Environment::Development];
        yield 'test' => ['test', Environment::Test];
        yield 'prod' => ['prod', Environment::Production];
    }

    public function testAnUnknownEnvironmentIsRejectedAndTheMessageListsTheAllowedValues(): void
    {
        $config = Config::fromArray(['MAILADMIN_ENV' => 'production']);

        try {
            $config->environment();
            self::fail('Expected a ConfigurationError.');
        } catch (ConfigurationError $error) {
            self::assertStringContainsString('dev, test, prod', $error->getMessage());
        }
    }

    public function testIntegerConversion(): void
    {
        $config = Config::fromArray(['A' => '3600', 'B' => '-1']);

        self::assertSame(3600, $config->int('A'));
        self::assertSame(-1, $config->int('B'));
        self::assertSame(50, $config->intOrDefault('MISSING', 50));
    }

    #[DataProvider('nonIntegers')]
    public function testANonIntegerIsRejected(string $value): void
    {
        $config = Config::fromArray(['A' => $value]);

        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('must be an integer');

        $config->int('A');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonIntegers(): iterable
    {
        yield 'float' => ['3.5'];
        yield 'word' => ['many'];
        yield 'suffix' => ['60s'];
        yield 'hex' => ['0x10'];
    }

    #[DataProvider('booleans')]
    public function testBooleanConversion(string $value, bool $expected): void
    {
        $config = Config::fromArray(['A' => $value]);

        self::assertSame($expected, $config->bool('A'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function booleans(): iterable
    {
        yield '1' => ['1', true];
        yield 'true' => ['true', true];
        yield 'TRUE' => ['TRUE', true];
        yield 'yes' => ['yes', true];
        yield 'on' => ['on', true];
        yield '0' => ['0', false];
        yield 'false' => ['false', false];
        yield 'no' => ['no', false];
        yield 'off' => ['off', false];
    }

    public function testAnAmbiguousBooleanIsRejectedRatherThanGuessed(): void
    {
        $config = Config::fromArray(['A' => 'maybe']);

        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('must be a boolean');

        $config->bool('A');
    }

    public function testBooleanDefault(): void
    {
        $config = Config::fromArray([]);

        self::assertTrue($config->boolOrDefault('MISSING', true));
        self::assertFalse($config->boolOrDefault('MISSING', false));
    }

    public function testEnumRejectsAValueOutsideTheAllowedSet(): void
    {
        $config = Config::fromArray(['A' => 'sqlite']);

        $this->expectException(ConfigurationError::class);

        $config->enum('A', ['mysql', 'mariadb']);
    }
}
