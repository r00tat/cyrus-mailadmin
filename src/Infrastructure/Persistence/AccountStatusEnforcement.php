<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

/**
 * Does the deployed view `allusers` honour `mailadmin_account_status` (U-127)?
 * What `bin/db-check.php` reports, in a class so that it can be tested.
 *
 * `allusers` is a `UNION` of two spellings of every account — the address
 * form derived from the stored name, and the stored name itself — and
 * `pam_mysql` accepts either. A view that filters one branch only still lets
 * a disabled account log in under the other spelling. So both checks look at
 * both (U-129):
 *
 *  - **the definition:** every branch of the `UNION` must name the status
 *    table, not merely the definition as a whole;
 *  - **the behaviour:** a disabled account must be missing from the view under
 *    its stored name **and** under the address form the view derives from it.
 */
final readonly class AccountStatusEnforcement
{
    public const string STATUS_TABLE = 'mailadmin_account_status';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * How many branches of the view's `UNION` there are, and how many of them
     * name the status table.
     *
     * @return array{branches: int, honouring: int}
     */
    public static function branches(string $definition): array
    {
        $parts = preg_split('/\bunion(?:\s+all|\s+distinct)?\b/i', $definition) ?: [$definition];
        $honouring = 0;

        foreach ($parts as $part) {
            if (stripos($part, self::STATUS_TABLE) !== false) {
                ++$honouring;
            }
        }

        return ['branches' => count($parts), 'honouring' => $honouring];
    }

    /**
     * Whether every branch names the status table.
     */
    public static function definitionHonoursStatus(string $definition): bool
    {
        $found = self::branches($definition);

        return $found['honouring'] === $found['branches'];
    }

    /**
     * How many disabled accounts the view still offers for login, under either
     * spelling. The address form is derived exactly as the view's first branch
     * derives it, `REPLACE` quirk included (architecture 3.3), because that is
     * the spelling `pam_mysql` would accept.
     *
     * @throws PersistenceError
     */
    public function leakingAccounts(): int
    {
        $leaking = $this->connection->selectValue(
            'SELECT COUNT(*) FROM `' . self::STATUS_TABLE . '` s '
            . 'JOIN `accountuser` ac ON ac.username = s.username '
            . 'WHERE s.disabled = 1 AND EXISTS ('
            . 'SELECT 1 FROM `allusers` a WHERE a.username = s.username '
            . "OR a.username = CONCAT(REPLACE(ac.username, CONCAT('.', ac.domain_name), ''), '@', ac.domain_name))",
        );

        return is_numeric($leaking) ? (int) $leaking : 0;
    }
}
