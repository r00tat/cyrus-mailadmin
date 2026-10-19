<?php

declare(strict_types=1);

namespace Mailadmin\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Writes a PSR-7 response to the SAPI.
 *
 * Written here for the same reason as {@see ServerRequestFactory}: the
 * dependency list is closed, and this is short.
 */
final class ResponseEmitter
{
    private const int CHUNK_SIZE = 8192;

    private function __construct()
    {
    }

    public static function emit(ResponseInterface $response): void
    {
        if (headers_sent($file, $line)) {
            // Nothing useful can be done for this response any more; leaving a
            // trace beats emitting a corrupt one silently.
            error_log(sprintf('mailadmin: headers already sent in %s:%d', (string) $file, (int) $line));
        } else {
            foreach ($response->getHeaders() as $name => $values) {
                $first = true;
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), $first);
                    $first = false;
                }
            }

            header(
                sprintf(
                    'HTTP/%s %d %s',
                    $response->getProtocolVersion(),
                    $response->getStatusCode(),
                    $response->getReasonPhrase(),
                ),
                true,
                $response->getStatusCode(),
            );
        }

        if (self::isBodyless($response->getStatusCode())) {
            return;
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            echo $body->read(self::CHUNK_SIZE);
        }
    }

    private static function isBodyless(int $status): bool
    {
        return $status === 204 || $status === 304 || ($status >= 100 && $status < 200);
    }
}
