<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Kernel;

use Mailadmin\Kernel\ContainerConfigurationError;
use Mailadmin\Kernel\DefinitionLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefinitionLoader::class)]
#[CoversClass(ContainerConfigurationError::class)]
final class DefinitionLoaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mailadmin-di-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0o750, true));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testDefinitionsFromSeveralFilesAreMerged(): void
    {
        $this->write('core.php', "return ['a' => 1, 'b' => 2];");
        $this->write('domains.php', "return ['c' => 3];");

        self::assertSame(
            ['a' => 1, 'b' => 2, 'c' => 3],
            DefinitionLoader::load($this->directory),
        );
    }

    public function testFilesAreLoadedInAlphabeticalOrder(): void
    {
        $this->write('zulu.php', "return ['z' => 'last'];");
        $this->write('alpha.php', "return ['a' => 'first'];");

        self::assertSame(['a', 'z'], array_keys(DefinitionLoader::load($this->directory)));
    }

    /**
     * U-5: with twenty packages contributing bindings, a last-one-wins merge would
     * make the wiring depend on file names, and whichever package lost would fail
     * somewhere far away from the cause.
     */
    public function testADuplicateKeyBreaksStartupAndNamesBothFiles(): void
    {
        $this->write('accounts.php', "return ['Shared\\\\Thing' => 1];");
        $this->write('domains.php', "return ['Shared\\\\Thing' => 2];");

        try {
            DefinitionLoader::load($this->directory);
            self::fail('Expected a ContainerConfigurationError.');
        } catch (ContainerConfigurationError $error) {
            self::assertStringContainsString('Shared\\Thing', $error->getMessage());
            self::assertStringContainsString('accounts.php', $error->getMessage());
            self::assertStringContainsString('domains.php', $error->getMessage());
        }
    }

    public function testADuplicateWithinASingleFileIsImpossibleAndSoIsNotAnError(): void
    {
        // PHP collapses duplicate array keys itself; the loader only has to catch
        // collisions across files.
        $this->write('core.php', "return ['a' => 1, 'a' => 2];");

        self::assertSame(['a' => 2], DefinitionLoader::load($this->directory));
    }

    public function testAFileThatDoesNotReturnAnArrayIsRejected(): void
    {
        $this->write('broken.php', "return 'not an array';");

        $this->expectException(ContainerConfigurationError::class);
        $this->expectExceptionMessage('broken.php');

        DefinitionLoader::load($this->directory);
    }

    public function testAFileWithoutAReturnStatementIsRejected(): void
    {
        $this->write('empty.php', '$unused = 1;');

        $this->expectException(ContainerConfigurationError::class);

        DefinitionLoader::load($this->directory);
    }

    public function testANumericKeyIsRejected(): void
    {
        // A definition is keyed by a class name, an interface name or a dotted
        // identifier. A list entry means someone forgot the key.
        $this->write('broken.php', "return ['SomeClass' => 1, 2];");

        $this->expectException(ContainerConfigurationError::class);
        $this->expectExceptionMessage('non-string key');

        DefinitionLoader::load($this->directory);
    }

    public function testAnEmptyDirectoryYieldsNoDefinitions(): void
    {
        self::assertSame([], DefinitionLoader::load($this->directory));
    }

    public function testAMissingDirectoryIsAnError(): void
    {
        $this->expectException(ContainerConfigurationError::class);

        DefinitionLoader::load($this->directory . '/nope');
    }

    public function testNonPhpFilesAreIgnored(): void
    {
        $this->write('core.php', "return ['a' => 1];");
        file_put_contents($this->directory . '/notes.md', 'ignore me');
        file_put_contents($this->directory . '/core.php.bak', "<?php return ['a' => 99];");

        self::assertSame(['a' => 1], DefinitionLoader::load($this->directory));
    }

    private function write(string $name, string $body): void
    {
        file_put_contents($this->directory . '/' . $name, "<?php\n\ndeclare(strict_types=1);\n\n" . $body . "\n");
    }
}
