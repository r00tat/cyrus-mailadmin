<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Access;

use Mailadmin\Domain\Model\DomainName;

/**
 * Which domains an actor can see — the `domainadmin` rows, as a value.
 *
 * „All domains" is a state of its own, not a list that happens to contain
 * everything: the legacy schema writes it as the single row `domain_name = '*'`,
 * and a scope that has to enumerate 114 domains to say „all" is a scope that
 * silently shrinks when someone adds the 115th.
 */
final readonly class DomainScope
{
    /**
     * @param list<DomainName> $domains empty when {@see $unrestricted} is true
     */
    private function __construct(
        private bool $unrestricted,
        private array $domains,
    ) {
    }

    public static function all(): self
    {
        return new self(true, []);
    }

    public static function of(DomainName ...$domains): self
    {
        return new self(false, array_values($domains));
    }

    public function isUnrestricted(): bool
    {
        return $this->unrestricted;
    }

    public function includes(DomainName $domain): bool
    {
        if ($this->unrestricted) {
            return true;
        }

        foreach ($this->domains as $visible) {
            if ($visible->equals($domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The named domains. Empty for an unrestricted scope — ask
     * {@see isUnrestricted()} before reading „no domains" out of that.
     *
     * @return list<DomainName>
     */
    public function domains(): array
    {
        return $this->domains;
    }

    public function isEmpty(): bool
    {
        return !$this->unrestricted && $this->domains === [];
    }

    /**
     * @return list<string>
     */
    public function domainNames(): array
    {
        $names = [];

        foreach ($this->domains as $domain) {
            $names[] = $domain->toString();
        }

        return $names;
    }
}
