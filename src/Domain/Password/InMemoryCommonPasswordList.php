<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Password;

/**
 * A block list held in memory — for tests and for the rare caller that wants a
 * fixed short list.
 *
 * The production list comes from
 * {@see \Mailadmin\Infrastructure\Security\FileCommonPasswordList}.
 */
final readonly class InMemoryCommonPasswordList implements CommonPasswordList
{
    /** @var array<string, true> */
    private array $entries;

    /**
     * @param list<string> $entries case is irrelevant; they are lower-cased here
     */
    public function __construct(array $entries = [])
    {
        $map = [];

        foreach ($entries as $entry) {
            $normalised = strtolower(trim($entry));

            if ($normalised === '') {
                continue;
            }

            $map[$normalised] = true;
        }

        $this->entries = $map;
    }

    public function contains(string $candidate): bool
    {
        return isset($this->entries[strtolower($candidate)]);
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
