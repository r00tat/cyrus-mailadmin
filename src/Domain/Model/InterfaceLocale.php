<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model;

/**
 * A language the frontend ships a catalogue for (W2, U-89).
 *
 * The server never translates anything (E-16) — the API stays language-neutral
 * and answers with stable codes. It knows this list for one reason only: it
 * stores which of these languages an administrator has chosen, and it must not
 * store one the interface cannot render.
 *
 * **This enumeration, `InterfaceLocale` in the contract and the catalogues in
 * `frontend/src/i18n/` are the same list.** A contract test holds the first two
 * together, a frontend test the last two.
 *
 * German only for now (U-91). Adding English is a case here, a value in the
 * contract and a catalogue file — nothing else moves.
 */
enum InterfaceLocale: string
{
    case German = 'de';

    /**
     * A stored value, read leniently.
     *
     * A value this build does not offer — written by a newer release and then
     * rolled back, or edited by hand — is not an error worth failing a request
     * over. It reads as „nothing chosen", and the interface follows the browser.
     */
    public static function fromStored(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
