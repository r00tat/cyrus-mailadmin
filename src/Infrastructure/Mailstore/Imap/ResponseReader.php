<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Imap;

use Mailadmin\Infrastructure\Mailstore\ConnectionFailed;
use Mailadmin\Infrastructure\Mailstore\Net\StreamTransport;
use Mailadmin\Infrastructure\Mailstore\Net\Transport;
use Mailadmin\Infrastructure\Mailstore\ProtocolError;
use Mailadmin\Infrastructure\Mailstore\ReadTimedOut;

/**
 * Reads one *logical* response line off a {@see Transport}.
 *
 * A logical line is not a line. `* LIST () "/" {13}` announces thirteen raw
 * bytes that follow the CRLF, and those bytes may themselves contain CRLF, a
 * quotation mark or a brace. Reading the transport line by line and hoping is
 * how a mailbox called `New` + CRLF + `Mail` turns into two responses, one of
 * which parses as something else entirely.
 *
 * So: read the line, and while it ends in `{n}`, take the n bytes, park them in
 * {@see ResponseLine::$literals}, put a placeholder in their place and read on.
 * A line can carry several literals — `* ACL {5}` … `{7}` … — hence the loop.
 *
 * **The size limit is not politeness.** `{n}` is a number the *server* chooses.
 * Without a ceiling a hostile or broken peer can make the client allocate as
 * much memory as it likes by announcing it, and PHP's memory limit turns that
 * into a fatal error rather than a caught exception.
 */
final class ResponseReader
{
    private readonly int $maxLiteral;

    public function __construct(
        private readonly Transport $transport,
        private readonly ProtocolTrace $trace,
        ?int $maxLiteral = null,
    ) {
        $this->maxLiteral = $maxLiteral ?? StreamTransport::MAX_LITERAL;
    }

    /**
     * @throws ProtocolError    when a literal is oversized or short
     * @throws ReadTimedOut
     * @throws ConnectionFailed
     */
    public function read(): ResponseLine
    {
        $text = $this->transport->readLine();
        $literals = [];

        while (($announced = Literal::announcedLength($text)) !== null) {
            if ($announced > $this->maxLiteral) {
                throw ProtocolError::literalTooLarge($announced, $this->maxLiteral);
            }

            $bytes = $this->transport->readBytes($announced);

            if (strlen($bytes) !== $announced) {
                throw ProtocolError::truncatedLiteral($announced, strlen($bytes));
            }

            $index = count($literals);
            $literals[] = $bytes;

            // Drop the `{n}` announcement, put the placeholder where it stood,
            // and append whatever the server wrote after the literal — which may
            // be another `{m}`, which is why this is a loop.
            $text = substr($text, 0, strlen($text) - strlen('{' . $announced . '}'))
                . ResponseLine::placeholder($index)
                . $this->transport->readLine();
        }

        $line = new ResponseLine($text, $literals);
        $this->trace->received($line);

        return $line;
    }
}
