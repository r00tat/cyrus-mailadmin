<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use DateTimeImmutable;
use Mailadmin\Application\Admin\AdminPreferences;
use Mailadmin\Application\Admin\AdminPreferenceStore;
use Mailadmin\Domain\Model\InterfaceLocale;

/**
 * {@see AdminPreferenceStore} without a database, for the use-case and
 * endpoint tests.
 *
 * It keeps raw strings, as the table does, so that a stored value this build
 * does not offer can be given and read back the way the real store reads it.
 *
 * **Not wired into the container** (U-37): tests construct it directly.
 */
final class InMemoryAdminPreferenceStore implements AdminPreferenceStore
{
    /** @var array<string, array{value: string, updated_at: DateTimeImmutable}> */
    private array $locales = [];

    public function givenStoredLocale(string $username, string $value, DateTimeImmutable $at): void
    {
        $this->locales[$username] = ['value' => $value, 'updated_at' => $at];
    }

    public function storedLocale(string $username): ?string
    {
        return $this->locales[$username]['value'] ?? null;
    }

    public function updatedAt(string $username): ?DateTimeImmutable
    {
        return $this->locales[$username]['updated_at'] ?? null;
    }

    public function find(string $username): AdminPreferences
    {
        return new AdminPreferences(InterfaceLocale::fromStored($this->storedLocale($username)));
    }

    public function save(string $username, AdminPreferences $preferences, DateTimeImmutable $at): void
    {
        if ($preferences->locale === null) {
            unset($this->locales[$username]);

            return;
        }

        $this->locales[$username] = ['value' => $preferences->locale->value, 'updated_at' => $at];
    }

    public function atomically(callable $work): mixed
    {
        // No rollback here: the use case writes the audit entry last, so a
        // failure before it has nothing to undo in a test.
        return $work();
    }
}
