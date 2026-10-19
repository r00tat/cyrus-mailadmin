<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Access;

use Mailadmin\Domain\Model\AdminRole;
use SensitiveParameter;

/**
 * What is needed to check one administrator's password, and nothing else.
 *
 * Separate from {@see ActorContext} on purpose. This carries a hash and is
 * built exactly once per sign-in; the actor context carries no secret and is
 * built on every authenticated request. Keeping them apart means the object
 * that travels through the whole request has nothing worth leaking in it.
 *
 * The stored hash may be in any of the legacy formats — DES-crypt, md5crypt,
 * SHA1 hex, MySQL `PASSWORD()`, plaintext. This class does not know that and
 * does not care: recognising the format is
 * {@see \Mailadmin\Infrastructure\Security\PasswordHasher}'s job (architecture
 * 5.5).
 */
final readonly class AdminCredentials
{
    public function __construct(
        public string $username,
        #[SensitiveParameter]
        public string $passwordHash,
        public AdminRole $role,
    ) {
    }

    /**
     * Keep the hash out of a `var_dump()`, a debugger and any log line that
     * happens to stringify an object graph.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'username' => $this->username,
            'passwordHash' => '[redacted]',
            'role' => $this->role->value,
        ];
    }
}
