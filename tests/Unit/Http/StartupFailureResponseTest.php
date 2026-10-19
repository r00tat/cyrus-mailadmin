<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Http;

use Mailadmin\Http\Middleware\RequestIdMiddleware;
use Mailadmin\Http\Problem\ErrorCode;
use Mailadmin\Http\SecurityHeaders;
use Mailadmin\Http\StartupFailureResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The response for a failure that happens before the middleware chain exists.
 * It has to look like every other error response even though it is produced
 * without the container, the PSR-7 stack or the logger.
 */
#[CoversClass(StartupFailureResponse::class)]
final class StartupFailureResponseTest extends TestCase
{
    public function testItIsAProblemDocumentWithTheInternalErrorCode(): void
    {
        $problem = StartupFailureResponse::problem('abc123def456');

        self::assertSame(500, $problem->status);
        self::assertSame(ErrorCode::INTERNAL_ERROR, $problem->code);
        self::assertSame('abc123def456', $problem->requestId);
    }

    public function testItLeaksNothingAboutTheCause(): void
    {
        $body = StartupFailureResponse::problem('abc123def456')->toJson();

        self::assertStringNotContainsString('MAILADMIN_', $body);
        self::assertStringNotContainsString('Mailadmin\\', $body);
        self::assertStringNotContainsString('/var/', $body);
        self::assertStringNotContainsString('Exception', $body);
    }

    /**
     * A startup failure is the response most likely to be rendered in a browser,
     * so it is the last one that may go out without a Content-Security-Policy.
     */
    public function testItCarriesEverySecurityHeader(): void
    {
        $headers = StartupFailureResponse::headers('abc123def456');

        foreach (SecurityHeaders::HEADERS as $name => $value) {
            self::assertArrayHasKey($name, $headers);
            self::assertSame($value, $headers[$name]);
        }
    }

    public function testItIsAnnouncedAsProblemJsonAndIsNotCached(): void
    {
        $headers = StartupFailureResponse::headers('abc123def456');

        self::assertSame('application/problem+json', $headers['Content-Type']);
        self::assertSame('no-store', $headers['Cache-Control']);
    }

    public function testItCarriesARequestIdTheOperatorCanSearchFor(): void
    {
        $headers = StartupFailureResponse::headers('abc123def456');

        self::assertSame('abc123def456', $headers[RequestIdMiddleware::HEADER]);
    }
}
