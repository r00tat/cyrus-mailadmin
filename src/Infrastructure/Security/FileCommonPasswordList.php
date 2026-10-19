<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Security;

use Mailadmin\Domain\Password\CommonPasswordList;
use RuntimeException;

/**
 * The block list of U-21, read from `resources/common-passwords.txt`.
 *
 * A plain text file rather than a package: E-12 keeps the dependency list
 * short, and a word list is data, not code. The file carries its own provenance
 * in a comment header.
 *
 * **Read once, on first use.** Most requests never validate a password, so the
 * file is not touched at start-up; the ones that do pay a single 100 KB read
 * and then answer from a hash map.
 *
 * **A missing file is an error, not an empty list.** Answering `false` for
 * every candidate would switch the rule off silently, and nothing downstream
 * would notice — the password would simply be accepted.
 */
final class FileCommonPasswordList implements CommonPasswordList
{
    /**
     * `array-key`, not `string`: PHP turns a numeric-string key such as
     * `123456` into an int. Lookups normalise the same way, so the map still
     * answers correctly — but the type has to say so.
     *
     * @var array<array-key, true>|null
     */
    private ?array $entries = null;

    public function __construct(private readonly string $path)
    {
    }

    public function contains(string $candidate): bool
    {
        if ($candidate === '') {
            return false;
        }

        return isset($this->entries()[strtolower($candidate)]);
    }

    /**
     * How many entries the list holds — for the deployment check and for tests.
     */
    public function count(): int
    {
        return count($this->entries());
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<array-key, true>
     */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(sprintf(
                'The common-password block list is missing or unreadable: %s. U-21 requires it; '
                . 'without it every common password would be accepted.',
                $this->path,
            ));
        }

        $entries = [];

        try {
            while (($line = fgets($handle)) !== false) {
                // `#` starts a comment. No entry in the shipped file begins with
                // one, and none may be added that does — see the file header.
                if ($line === '' || $line[0] === '#') {
                    continue;
                }

                $entry = strtolower(rtrim($line, "\r\n"));

                if ($entry === '') {
                    continue;
                }

                $entries[$entry] = true;
            }
        } finally {
            fclose($handle);
        }

        $this->entries = $entries;

        return $entries;
    }
}
