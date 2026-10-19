<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Mailstore;

use Mailadmin\Infrastructure\Mailstore\Imap\ProtocolTrace;

/**
 * The mailstore administrator's own credentials — never a user's.
 *
 * There is exactly one account in this object, and it is the one architecture
 * 9.3 describes as the `authcid`. A user's password never appears here, because
 * nothing in mailadmin ever logs in as a user: folders are created through SASL
 * proxy authorisation, which is the whole reason that section exists. The legacy
 * system did the opposite and needed the new user's clear-text password to do
 * it.
 *
 * **Two small defences, both against accidents rather than attackers:**
 *
 *  - the secret is private and reachable only through {@see secret()}, so every
 *    place that touches it is one grep away;
 *  - {@see __debugInfo()} keeps it out of `var_dump()` and out of any exception
 *    renderer that walks object properties. U-28's masking processor works on
 *    log *context field names* and cannot see inside an object, which is exactly
 *    why it replaces objects with their class name — this covers the paths that
 *    do not go through Monolog at all.
 */
final readonly class Credentials
{
    private function __construct(
        public string $administrator,
        private string $secret,
    ) {
    }

    /**
     * @throws ConfigurationInvalid when either half is missing — an empty
     *                              password would otherwise be sent to the
     *                              server and refused there, which reads like a
     *                              wrong password rather than a missing one
     */
    public static function of(
        string $administrator,
        #[\SensitiveParameter]
        string $secret,
    ): self {
        $name = trim($administrator);

        if ($name === '') {
            throw ConfigurationInvalid::unusableAdministrator('it is empty');
        }

        // A NUL would terminate one of the three fields of the SASL PLAIN
        // response early, so the server would read a different identity than
        // the one configured.
        if (str_contains($name, "\0") || str_contains($secret, "\0")) {
            throw ConfigurationInvalid::unusableAdministrator(
                'it contains a NUL byte, which would truncate the SASL exchange',
            );
        }

        if ($secret === '') {
            throw ConfigurationInvalid::missingCredential('MAILADMIN_IMAP_ADMIN_PASSWORD');
        }

        return new self($name, $secret);
    }

    public function secret(): string
    {
        return $this->secret;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'administrator' => $this->administrator,
            'secret' => ProtocolTrace::REDACTED,
        ];
    }
}
