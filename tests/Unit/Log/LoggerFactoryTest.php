<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Log;

use Mailadmin\Config\Config;
use Mailadmin\Log\LoggerFactory;
use Mailadmin\Log\RequestIdProcessor;
use Mailadmin\Log\SecretMaskingProcessor;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LoggerFactory::class)]
final class LoggerFactoryTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/mailadmin-log-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0o750, true));
        $this->temporaryDirectory = $directory;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporaryDirectory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->temporaryDirectory);
    }

    public function testTheDefaultTargetIsStderr(): void
    {
        $logger = $this->create(['MAILADMIN_ENV' => 'prod']);

        self::assertInstanceOf(Logger::class, $logger);
        $handler = $logger->getHandlers()[0];
        self::assertInstanceOf(StreamHandler::class, $handler);
        self::assertSame('php://stderr', $handler->getUrl());
    }

    public function testAFilePathSwitchesToARotatingFileHandler(): void
    {
        $logger = $this->create([
            'MAILADMIN_ENV' => 'prod',
            'MAILADMIN_LOG_TARGET' => $this->temporaryDirectory . '/mailadmin.log',
        ]);

        self::assertInstanceOf(Logger::class, $logger);
        self::assertInstanceOf(RotatingFileHandler::class, $logger->getHandlers()[0]);
    }

    /**
     * U-12: a log destination is never a reason to refuse service. Unlike the
     * cache directory (U-13), nothing downstream depends on it.
     */
    public function testAnUnwritableFileFallsBackToStderrAndSaysSo(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignores directory permissions.');
        }

        $unwritable = $this->temporaryDirectory . '/locked';
        self::assertTrue(mkdir($unwritable, 0o500));

        $logger = $this->create([
            'MAILADMIN_ENV' => 'prod',
            'MAILADMIN_LOG_TARGET' => $unwritable . '/mailadmin.log',
        ]);

        self::assertInstanceOf(Logger::class, $logger);
        $handler = $logger->getHandlers()[0];
        self::assertInstanceOf(StreamHandler::class, $handler);
        self::assertSame('php://stderr', $handler->getUrl());

        @chmod($unwritable, 0o750);
        @rmdir($unwritable);
    }

    #[DataProvider('levels')]
    public function testTheLevelIsTakenFromTheEnvironment(string $configured, Level $expected): void
    {
        $logger = $this->create(['MAILADMIN_ENV' => 'dev', 'MAILADMIN_LOG_LEVEL' => $configured]);

        self::assertInstanceOf(Logger::class, $logger);
        $handler = $logger->getHandlers()[0];
        self::assertInstanceOf(StreamHandler::class, $handler);
        self::assertSame($expected, $handler->getLevel());
    }

    /**
     * @return iterable<string, array{string, Level}>
     */
    public static function levels(): iterable
    {
        yield 'debug' => ['debug', Level::Debug];
        yield 'warning' => ['warning', Level::Warning];
        yield 'error' => ['error', Level::Error];
        yield 'upper case' => ['ERROR', Level::Error];
        yield 'default' => ['', Level::Info];
        yield 'nonsense degrades to info' => ['chatty', Level::Info];
    }

    /**
     * @param array<string, string> $environment
     */
    private function create(array $environment): \Psr\Log\LoggerInterface
    {
        return (new LoggerFactory(
            Config::fromArray($environment),
            new SecretMaskingProcessor(),
            new RequestIdProcessor(),
        ))->create();
    }
}
