<?php

declare(strict_types=1);

namespace Mailadmin\Application\Admin;

use Mailadmin\Domain\Model\InterfaceLocale;

/**
 * An administrator's own interface preferences (W2) — today, exactly one.
 *
 * `locale: null` is „nothing chosen": the frontend follows the browser's
 * language list, which is what the browser sends as `Accept-Language`, and
 * falls back to German (U-90). It is not a third language and not „German".
 */
final readonly class AdminPreferences
{
    /** The row name in `mailadmin_admin_setting`. Dotted, as 0050 prescribes. */
    public const string LOCALE = 'ui.locale';

    public function __construct(public ?InterfaceLocale $locale = null)
    {
    }

    public static function none(): self
    {
        return new self();
    }

    public function equals(self $other): bool
    {
        return $this->locale === $other->locale;
    }

    /**
     * The `AdminPreferences` schema, member for member. A contract test compares
     * the two.
     *
     * @return array{locale: string|null}
     */
    public function representation(): array
    {
        return ['locale' => $this->locale?->value];
    }
}
