<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Sieve;

/**
 * Everything one account has arranged in Sieve: out of office, forwards,
 * filters (architecture 9.4).
 *
 * **This is the source, the script is the output.** The legacy system kept both
 * in one script and pushed changes into it with regular expressions — the
 * reliable source of subtle bugs this package exists to remove. A change
 * produces a new rule set, {@see SieveRenderer} renders it completely, and the
 * previous script is replaced, never patched.
 */
final readonly class SieveRuleSet
{
    /**
     * @param list<RedirectRule> $redirects
     * @param list<FilterRule>   $filters
     */
    private function __construct(
        private ?VacationRule $vacation,
        private array $redirects,
        private array $filters,
        private bool $keepLocalCopy,
    ) {
    }

    public static function empty(): self
    {
        return new self(null, [], [], true);
    }

    /**
     * @param list<RedirectRule> $redirects
     * @param list<FilterRule>   $filters
     */
    public static function of(
        ?VacationRule $vacation = null,
        array $redirects = [],
        array $filters = [],
        bool $keepLocalCopy = true,
    ): self {
        return new self(
            $vacation,
            array_values($redirects),
            array_values($filters),
            $keepLocalCopy,
        );
    }

    public function vacation(): ?VacationRule
    {
        return $this->vacation;
    }

    /**
     * @return list<RedirectRule>
     */
    public function redirects(): array
    {
        return $this->redirects;
    }

    /**
     * @return list<FilterRule>
     */
    public function filters(): array
    {
        return $this->filters;
    }

    /**
     * Does the mailbox keep the message when it is redirected?
     *
     * Without a redirect the question is moot — Sieve keeps by default — so
     * this is only meaningful together with {@see hasRedirects()}.
     */
    public function keepsLocalCopy(): bool
    {
        return $this->keepLocalCopy;
    }

    public function hasVacation(): bool
    {
        return $this->vacation !== null;
    }

    public function hasRedirects(): bool
    {
        return $this->redirects !== [];
    }

    public function hasFilters(): bool
    {
        return $this->filters !== [];
    }

    /**
     * Nothing arranged. A rule set that renders to a script with no action is
     * a script that should be deleted rather than written (A3 decides).
     */
    public function isEmpty(): bool
    {
        return $this->vacation === null && $this->redirects === [] && $this->filters === [];
    }

    public function withVacation(?VacationRule $vacation): self
    {
        return new self($vacation, $this->redirects, $this->filters, $this->keepLocalCopy);
    }

    public function withRedirect(RedirectRule $redirect): self
    {
        foreach ($this->redirects as $existing) {
            if ($existing->equals($redirect)) {
                return $this;
            }
        }

        return new self(
            $this->vacation,
            [...$this->redirects, $redirect],
            $this->filters,
            $this->keepLocalCopy,
        );
    }

    /**
     * @param list<RedirectRule> $redirects
     */
    public function withRedirects(array $redirects): self
    {
        return new self(
            $this->vacation,
            array_values($redirects),
            $this->filters,
            $this->keepLocalCopy,
        );
    }

    public function withFilter(FilterRule $filter): self
    {
        return new self(
            $this->vacation,
            $this->redirects,
            [...$this->filters, $filter],
            $this->keepLocalCopy,
        );
    }

    public function withLocalCopy(bool $keepLocalCopy): self
    {
        return new self($this->vacation, $this->redirects, $this->filters, $keepLocalCopy);
    }

    /**
     * The extensions the rendered script will declare in `require`.
     *
     * M3 asks the server for its capabilities when it connects (its open point
     * 2) and can compare — an extension the server does not have makes the
     * whole script invalid, and finding that out at `PUTSCRIPT` time is late.
     *
     * @return list<string>
     */
    public function requiredExtensions(): array
    {
        $extensions = [];

        if ($this->vacation !== null) {
            $extensions[] = 'vacation';
        }

        foreach ($this->filters as $filter) {
            foreach ($filter->requiredExtensions() as $extension) {
                $extensions[] = $extension;
            }
        }

        $unique = array_values(array_unique($extensions));
        sort($unique);

        return $unique;
    }
}
