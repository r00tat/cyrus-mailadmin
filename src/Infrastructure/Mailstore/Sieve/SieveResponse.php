<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Sieve;

/**
 * What one ManageSieve command produced: the data lines, then the completion.
 *
 * The data lines are kept **per command** rather than in a running buffer. That
 * matters for the same reason it does in M1: `LISTSCRIPTS` and the capability
 * response both answer with bare lines, and a client that collected them
 * globally would have to guess which `"sieve" ACTIVE` belonged to which
 * question.
 *
 * A `BYE` is a completion like the other two. The server is entitled to end the
 * session at any point — an idle timeout, a shutdown — and treating that as a
 * refusal of the command would send the caller looking for a fault in the
 * script it just wrote.
 */
final readonly class SieveResponse
{
    public const string OK = 'OK';
    public const string NO = 'NO';
    public const string BYE = 'BYE';

    /**
     * @param list<SieveLine> $lines the data lines that preceded the completion
     */
    private function __construct(
        public string $status,
        public ?string $code,
        public string $text,
        public array $lines,
    ) {
    }

    /**
     * @param list<SieveLine> $lines
     */
    public static function of(SieveLine $completion, array $lines): self
    {
        return new self(
            $completion->statusWord() ?? self::NO,
            $completion->responseCode(),
            $completion->completionText(),
            $lines,
        );
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }

    public function isBye(): bool
    {
        return $this->status === self::BYE;
    }

    /**
     * Does the completion carry this response code?
     *
     * Matches a family as well as an exact code: `hasCode('QUOTA')` is true for
     * `QUOTA/MAXSCRIPTS`, because RFC 5804 defines the slash as a refinement of
     * the code before it and a caller that only knows the family should still
     * recognise it.
     */
    public function hasCode(string $code): bool
    {
        if ($this->code === null) {
            return false;
        }

        $wanted = strtoupper($code);

        return $this->code === $wanted || str_starts_with($this->code, $wanted . '/');
    }

    /**
     * The server's prose, flattened and capped.
     *
     * Two reasons, both about what ends up in a log. A `NO` from timsieved after
     * a rejected `PUTSCRIPT` quotes the offending line of the script, and a Sieve
     * script holds a vacation message — personal data that has no business being
     * repeated in full. And a stray CR in server output splits a log line in two.
     *
     * Enough of it survives to name the syntax error, which is what an operator
     * needs; the whole of it does not.
     */
    public function operatorText(int $limit = 200): string
    {
        $flat = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $this->text) ?? $this->text;
        $flat = trim($flat);

        return strlen($flat) > $limit ? substr($flat, 0, $limit - 3) . '...' : $flat;
    }
}
