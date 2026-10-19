<?php

declare(strict_types=1);

namespace Mailadmin\Log;

use Mailadmin\Config\Config;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Builds the application logger: structured JSON, one record per line (U-12).
 *
 * `stderr` is the default because it works in a container and under PHP-FPM
 * without any directory having to exist. `MAILADMIN_LOG_TARGET` may name a file
 * instead; if that file cannot be written the logger falls back to stderr and
 * says so once. A log destination is not a reason to refuse service — unlike a
 * missing cache directory (U-13), nothing downstream depends on it.
 */
final class LoggerFactory
{
    private const string CHANNEL = 'mailadmin';
    private const string STDERR = 'stderr';

    /** Kept for a fortnight; long enough to investigate, short enough not to fill a disk. */
    private const int FILE_ROTATION_DAYS = 14;

    public function __construct(
        private readonly Config $config,
        private readonly SecretMaskingProcessor $secretMasking,
        private readonly RequestIdProcessor $requestId,
    ) {
    }

    public function create(): LoggerInterface
    {
        $level = $this->level();
        $target = $this->config->logTarget();

        $fallbackReason = null;
        $handler = null;

        if ($target !== self::STDERR) {
            $fallbackReason = self::whyFileTargetIsUnusable($target);

            if ($fallbackReason === null) {
                $handler = new RotatingFileHandler($target, self::FILE_ROTATION_DAYS, $level);
                $handler->setFormatter($this->formatter());
            }
        }

        if ($handler === null) {
            $handler = $this->stderrHandler($level);
        }

        $logger = new Logger(self::CHANNEL, [$handler]);

        // Monolog applies processors in reverse push order, so the masking
        // processor is pushed first in order to run *last*. Everything any other
        // processor adds therefore passes through it as well.
        $logger->pushProcessor($this->secretMasking);
        $logger->pushProcessor($this->requestId);

        if ($fallbackReason !== null) {
            $logger->warning('Falling back to stderr; the configured log file is not usable.', [
                'configured_target' => $target,
                'reason' => $fallbackReason,
            ]);
        }

        return $logger;
    }

    /**
     * Monolog opens its stream on the first write, so an unusable path would
     * otherwise only surface as an exception thrown from inside a log call — at
     * the very moment something is already going wrong. The check happens up
     * front instead.
     *
     * @return string|null the reason, or null if the target can be used
     */
    private static function whyFileTargetIsUnusable(string $target): ?string
    {
        if (!str_starts_with($target, '/')) {
            return 'the path is not absolute';
        }

        if (file_exists($target)) {
            return is_writable($target) ? null : 'the file is not writable';
        }

        $directory = dirname($target);

        if (!is_dir($directory)) {
            return 'the directory does not exist';
        }

        return is_writable($directory) ? null : 'the directory is not writable';
    }

    private function stderrHandler(Level $level): HandlerInterface
    {
        $handler = new StreamHandler('php://stderr', $level);
        $handler->setFormatter($this->formatter());

        return $handler;
    }

    private function formatter(): JsonFormatter
    {
        return new JsonFormatter(
            batchMode: JsonFormatter::BATCH_MODE_JSON,
            appendNewline: true,
            ignoreEmptyContextAndExtra: true,
            includeStacktraces: false,
        );
    }

    /**
     * An unknown level name is not worth refusing to start over; it degrades to
     * `info`, and the mistake is visible in the very next log line.
     */
    private function level(): Level
    {
        return match (strtolower($this->config->logLevel())) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Info,
        };
    }
}
