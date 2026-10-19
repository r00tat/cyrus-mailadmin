<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model;

use Mailadmin\Domain\Exception\InvalidDomainConfiguration;

/**
 * The folders a new mailbox in this domain gets — `domain.folders`.
 *
 * The legacy column is a comma separated list, and it stays one (E-1): the
 * format is kept, the parsing is not. Empty entries and stray whitespace are
 * dropped, order is preserved, duplicates are removed case insensitively —
 * Cyrus would reject the second `CREATE` anyway.
 *
 * A folder name is a path below the INBOX, so `/` is allowed as the hierarchy
 * separator (`unixhierarchysep: yes`) — `Archive/2026` is one folder. Whether a
 * change applies to accounts that already exist is a use case question (A1,
 * point 7), not one this value object answers.
 */
final readonly class DefaultFolders
{
    /** The separator in the legacy column. */
    public const string LEGACY_SEPARATOR = ',';

    /**
     * @param list<string> $names
     */
    private function __construct(private array $names)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @throws InvalidDomainConfiguration
     */
    public static function of(string ...$names): self
    {
        $clean = [];
        $seen = [];

        foreach ($names as $name) {
            $folder = self::guard($name);
            $key = strtolower($folder);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $clean[] = $folder;
        }

        return new self($clean);
    }

    /**
     * Parse `domain.folders`. Empty entries are dropped, not rejected — the
     * legacy default for the column is an empty string.
     *
     * @throws InvalidDomainConfiguration
     */
    public static function fromLegacyString(string $value): self
    {
        $parts = explode(self::LEGACY_SEPARATOR, $value);
        $names = [];

        foreach ($parts as $part) {
            if (trim($part) !== '') {
                $names[] = $part;
            }
        }

        return self::of(...$names);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return $this->names;
    }

    public function isEmpty(): bool
    {
        return $this->names === [];
    }

    public function count(): int
    {
        return count($this->names);
    }

    public function contains(string $name): bool
    {
        foreach ($this->names as $folder) {
            if (strcasecmp($folder, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The value for `domain.folders`.
     */
    public function toLegacyString(): string
    {
        return implode(self::LEGACY_SEPARATOR, $this->names);
    }

    public function equals(self $other): bool
    {
        return $this->names === $other->names;
    }

    /**
     * @throws InvalidDomainConfiguration
     */
    private static function guard(string $name): string
    {
        $folder = trim($name);

        if ($folder === '') {
            throw InvalidDomainConfiguration::emptyFolderName();
        }

        if (str_contains($folder, self::LEGACY_SEPARATOR)) {
            throw InvalidDomainConfiguration::unusableFolderName(
                $folder,
                'it contains the separator of the legacy column',
            );
        }

        if (str_contains($folder, '@')) {
            throw InvalidDomainConfiguration::unusableFolderName($folder, 'it contains "@"');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $folder) === 1) {
            throw InvalidDomainConfiguration::unusableFolderName(
                $folder,
                'it contains a control character',
            );
        }

        if (str_starts_with($folder, '/') || str_ends_with($folder, '/')) {
            throw InvalidDomainConfiguration::unusableFolderName(
                $folder,
                'it starts or ends with the hierarchy separator',
            );
        }

        if (str_contains($folder, '//')) {
            throw InvalidDomainConfiguration::unusableFolderName(
                $folder,
                'it contains an empty hierarchy level',
            );
        }

        return $folder;
    }
}
