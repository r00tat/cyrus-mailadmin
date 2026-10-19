<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Security;

use DI\ContainerBuilder;
use Mailadmin\Config\Config;
use Mailadmin\Domain\Password\CommonPasswordList;
use Mailadmin\Domain\Password\PasswordPolicy;
use Mailadmin\Domain\Password\PasswordSubject;
use Mailadmin\Infrastructure\Security\FileCommonPasswordList;
use Mailadmin\Infrastructure\Security\PasswordGenerator;
use Mailadmin\Infrastructure\Security\PasswordHasher;
use Mailadmin\Infrastructure\Security\PasswordHashingOptions;
use Mailadmin\Runtime\Paths;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * `config/di/security.php` — that the wiring of S1 actually resolves.
 *
 * Only this one definition file is loaded, plus the two entries the kernel
 * registers before the container exists. Pulling in the whole `config/di`
 * directory would make this test fail whenever another package's wiring is
 * mid-edit, which says nothing about S1.
 */
#[CoversNothing]
final class SecurityDefinitionsTest extends TestCase
{
    public function testThePolicyResolvesAgainstTheShippedBlockList(): void
    {
        $list = $this->container()->get(CommonPasswordList::class);

        self::assertInstanceOf(FileCommonPasswordList::class, $list);
        self::assertSame(
            dirname(__DIR__, 4) . '/resources/common-passwords.txt',
            $list->path(),
        );
        self::assertTrue($list->contains('password123'));
    }

    public function testTheHasherResolvesAndWritesTheTargetFormatPerTable(): void
    {
        $container = $this->container();

        $hasher = $container->get(PasswordHasher::class);
        $policy = $container->get(PasswordPolicy::class);

        self::assertInstanceOf(PasswordHasher::class, $hasher);
        self::assertInstanceOf(PasswordPolicy::class, $policy);

        $plaintext = 'Untypischer-Sonnenschirm-42';

        self::assertStringStartsWith(
            '$2y$',
            $hasher->hash($policy->accept($plaintext, PasswordSubject::AccountUser)),
        );
        self::assertStringStartsWith(
            '$argon2id$',
            $hasher->hash($policy->accept($plaintext, PasswordSubject::AdminUser)),
        );
    }

    public function testTheGeneratorResolves(): void
    {
        $generator = $this->container()->get(PasswordGenerator::class);

        self::assertInstanceOf(PasswordGenerator::class, $generator);
        self::assertSame(19, strlen($generator->generate(PasswordSubject::AccountUser)->reveal()));
    }

    public function testTheCostParametersComeFromTheEnvironment(): void
    {
        $options = $this->container([
            PasswordHashingOptions::BCRYPT_COST => '12',
        ])->get(PasswordHashingOptions::class);

        self::assertInstanceOf(PasswordHashingOptions::class, $options);
        self::assertSame(12, $options->bcryptCost());
    }

    /**
     * @param array<string, string> $environment
     */
    private function container(array $environment = []): ContainerInterface
    {
        $paths = new Paths(dirname(__DIR__, 4));

        $definitions = require $paths->join('config', 'di', 'security.php');

        self::assertIsArray($definitions);

        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            Paths::class => $paths,
            Config::class => Config::fromArray($environment),
        ]);
        $builder->addDefinitions($definitions);

        return $builder->build();
    }
}
