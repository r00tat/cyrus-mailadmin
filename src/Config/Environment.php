<?php

declare(strict_types=1);

namespace Mailadmin\Config;

/**
 * The three environments the application distinguishes (U-9, U-11, U-12, U-13).
 *
 * There is no fallback: `MAILADMIN_ENV` is mandatory. Guessing wrong in either
 * direction is harmful — a production instance silently treated as development
 * would load a `.env`, expose the schema browser and recompile build artefacts
 * under load.
 */
enum Environment: string
{
    case Development = 'dev';
    case Test = 'test';
    case Production = 'prod';

    public function isDevelopment(): bool
    {
        return $this === self::Development;
    }

    public function isTest(): bool
    {
        return $this === self::Test;
    }

    public function isProduction(): bool
    {
        return $this === self::Production;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
