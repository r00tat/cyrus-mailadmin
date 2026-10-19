<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore\Imap;

/**
 * What a command produced: its completion status and the untagged lines that
 * belonged to it.
 *
 * The untagged lines are collected per command rather than globally, which is
 * only meaningful because every command carries its own tag (architecture 9.2).
 * With a constant tag there is no way to say which `* QUOTA` line answers which
 * `GETQUOTA`.
 */
final readonly class CommandResult
{
    /**
     * @param list<ResponseLine> $untagged
     */
    public function __construct(
        public ResponseStatus $status,
        public string $text,
        public ?string $responseCode,
        public array $untagged,
    ) {
    }

    /**
     * @param list<ResponseLine> $untagged
     */
    public function withUntagged(array $untagged): self
    {
        return new self($this->status, $this->text, $this->responseCode, $untagged);
    }

    public function isOk(): bool
    {
        return $this->status->isOk();
    }

    public function hasResponseCode(string $code): bool
    {
        return $this->responseCode !== null && strcasecmp($this->responseCode, $code) === 0;
    }

    /**
     * The untagged lines whose first word is `$keyword`.
     *
     * @return list<ResponseLine>
     */
    public function linesWith(string $keyword): array
    {
        $matching = [];

        foreach ($this->untagged as $line) {
            if ($line->hasKeyword($keyword)) {
                $matching[] = $line;
            }
        }

        return $matching;
    }

    public function firstLineWith(string $keyword): ?ResponseLine
    {
        return $this->linesWith($keyword)[0] ?? null;
    }
}
