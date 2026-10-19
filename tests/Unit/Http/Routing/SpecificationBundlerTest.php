<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Http\Routing;

use Mailadmin\Http\Routing\ContractBuildError;
use Mailadmin\Http\Routing\SpecificationBundler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The contract is one document in several files (U-3). These tests hold the
 * three properties that makes it safe: nothing is overwritten silently, the
 * output is deterministic, and no reference is left dangling.
 */
#[CoversClass(SpecificationBundler::class)]
final class SpecificationBundlerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mailadmin-bundler-' . bin2hex(random_bytes(6));

        mkdir($this->directory . '/paths', 0o777, true);
        mkdir($this->directory . '/components', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (['paths', 'components'] as $sub) {
            foreach (glob($this->directory . '/' . $sub . '/*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($this->directory . '/' . $sub);
        }

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->directory);
    }

    public function testMergesPathsComponentsAndTagsFromEveryFile(): void
    {
        $this->writeRoot();
        $this->write('paths/widgets.yaml', [
            'tags' => [['name' => 'widgets', 'description' => 'Things.']],
            'paths' => ['/widgets' => ['get' => ['operationId' => 'listWidgets']]],
        ]);
        $this->write('components/widget.yaml', [
            'components' => ['schemas' => ['Widget' => ['type' => 'object']]],
        ]);

        $document = $this->bundler()->bundle();

        self::assertSame('3.0.3', $document['openapi']);
        self::assertArrayHasKey('/widgets', $document['paths']);
        self::assertArrayHasKey('Widget', $document['components']['schemas']);
        self::assertArrayHasKey('Problem', $document['components']['schemas']);
        self::assertSame([['name' => 'widgets', 'description' => 'Things.']], $document['tags']);
    }

    public function testTwoFilesDeclaringTheSamePathIsAnErrorNamingBoth(): void
    {
        $this->writeRoot();
        $this->write('paths/a-widgets.yaml', ['paths' => ['/widgets' => ['get' => []]]]);
        $this->write('paths/b-gadgets.yaml', ['paths' => ['/widgets' => ['post' => []]]]);

        $this->expectException(ContractBuildError::class);
        $this->expectExceptionMessageMatches('/a-widgets\.yaml/');
        $this->expectExceptionMessageMatches('/b-gadgets\.yaml/');

        $this->bundler()->bundle();
    }

    public function testTwoFilesDeclaringTheSameComponentIsAnErrorNamingBoth(): void
    {
        $this->writeRoot();
        $this->write('components/a-widget.yaml', ['components' => ['schemas' => ['Thing' => ['type' => 'object']]]]);
        $this->write('components/b-gadget.yaml', ['components' => ['schemas' => ['Thing' => ['type' => 'string']]]]);

        $this->expectException(ContractBuildError::class);
        $this->expectExceptionMessageMatches('/schemas\/Thing/');
        $this->expectExceptionMessageMatches('/a-widget\.yaml/');
        $this->expectExceptionMessageMatches('/b-gadget\.yaml/');

        $this->bundler()->bundle();
    }

    public function testACollisionWithAComponentTheRootAlreadyDefinesIsAlsoAnError(): void
    {
        $this->writeRoot();
        $this->write('components/mine.yaml', ['components' => ['schemas' => ['Problem' => ['type' => 'string']]]]);
        $this->write('components/aaa-common.yaml', ['components' => ['schemas' => ['Other' => ['type' => 'string']]]]);

        // `Problem` comes from the fixture root, so the second definition loses.
        $this->expectException(ContractBuildError::class);
        $this->expectExceptionMessageMatches('/schemas\/Problem/');

        $this->bundler()->bundle();
    }

    public function testADuplicateTagIsAnError(): void
    {
        $this->writeRoot();
        $this->write('paths/a.yaml', ['tags' => [['name' => 'widgets']], 'paths' => ['/a' => []]]);
        $this->write('paths/b.yaml', ['tags' => [['name' => 'widgets']], 'paths' => ['/b' => []]]);

        $this->expectException(ContractBuildError::class);
        $this->expectExceptionMessageMatches('/Tag "widgets" is declared twice/');

        $this->bundler()->bundle();
    }

    public function testAPartialMayNotContributeAnythingButPathsComponentsAndTags(): void
    {
        $this->writeRoot();
        $this->write('paths/widgets.yaml', ['servers' => [['url' => '/elsewhere']]]);

        $this->expectException(ContractBuildError::class);
        $this->expectExceptionMessageMatches('/top-level key "servers"/');

        $this->bundler()->bundle();
    }

    public function testAFileRelativeReferenceBecomesAnInternalPointer(): void
    {
        $this->writeRoot();
        $this->write('paths/widgets.yaml', [
            'paths' => [
                '/widgets' => [
                    'get' => [
                        'operationId' => 'listWidgets',
                        'responses' => [
                            '500' => ['$ref' => '../components/common.yaml#/components/schemas/Problem'],
                        ],
                    ],
                ],
            ],
        ]);

        $document = $this->bundler()->bundle();

        self::assertSame(
            '#/components/schemas/Problem',
            $document['paths']['/widgets']['get']['responses'][500]['$ref'],
        );
    }

    public function testAReferenceToSomethingThatDoesNotExistIsAnError(): void
    {
        $this->writeRoot();
        $this->write('paths/widgets.yaml', [
            'paths' => ['/widgets' => ['get' => ['responses' => ['200' => ['$ref' => '#/components/schemas/Ghost']]]]],
        ]);

        $this->expectException(ContractBuildError::class);
        $this->expectExceptionMessageMatches('/schemas\/Ghost/');

        $this->bundler()->bundle();
    }

    public function testAReferenceToAWholeFileIsAnError(): void
    {
        $this->writeRoot();
        $this->write('paths/widgets.yaml', [
            'paths' => ['/widgets' => ['get' => ['responses' => ['200' => ['$ref' => 'widget.yaml']]]]],
        ]);

        $this->expectException(ContractBuildError::class);
        $this->expectExceptionMessageMatches('/points at a whole file/');

        $this->bundler()->bundle();
    }

    public function testTheOutputDoesNotDependOnTheOrderTheFilesHappenToBeRead(): void
    {
        $this->writeRoot();
        $this->write('paths/zebra.yaml', ['paths' => ['/zebra' => ['get' => ['operationId' => 'z']]]]);
        $this->write('paths/alpha.yaml', ['paths' => ['/alpha' => ['get' => ['operationId' => 'a']]]]);
        $this->write('components/zed.yaml', ['components' => ['schemas' => ['Zed' => ['type' => 'string']]]]);
        $this->write('components/abel.yaml', ['components' => ['schemas' => ['Abel' => ['type' => 'string']]]]);

        $first = SpecificationBundler::toYaml($this->bundler()->bundle());
        $second = SpecificationBundler::toYaml($this->bundler()->bundle());

        self::assertSame($first, $second);
        self::assertSame(['/alpha', '/zebra'], array_keys($this->bundler()->bundle()['paths']));
        self::assertSame(
            ['Abel', 'Problem', 'Zed'],
            array_keys($this->bundler()->bundle()['components']['schemas']),
        );
    }

    public function testTheSourceFingerprintChangesWhenAPartialChanges(): void
    {
        $this->writeRoot();
        $this->write('paths/widgets.yaml', ['paths' => ['/widgets' => ['get' => ['operationId' => 'listWidgets']]]]);

        $before = $this->bundler()->sourceFingerprint();

        $this->write('paths/widgets.yaml', ['paths' => ['/widgets' => ['get' => ['operationId' => 'findWidgets']]]]);

        self::assertNotSame($before, $this->bundler()->sourceFingerprint());
    }

    public function testTheSourceFingerprintChangesWhenAPartialAppears(): void
    {
        $this->writeRoot();
        $before = $this->bundler()->sourceFingerprint();

        $this->write('paths/widgets.yaml', ['paths' => ['/widgets' => []]]);

        self::assertNotSame($before, $this->bundler()->sourceFingerprint());
    }

    public function testEmptyCollectionsSurviveTheYamlRoundTripAsLists(): void
    {
        $this->writeRoot();
        $this->write('paths/widgets.yaml', [
            'paths' => ['/widgets' => ['get' => ['operationId' => 'listWidgets', 'security' => []]]],
        ]);

        /** @var array<string, mixed> $reparsed */
        $reparsed = Yaml::parse(SpecificationBundler::toYaml($this->bundler()->bundle()));

        // `security: []` marks a public operation. Written as `{}` it would read
        // back as a map and no longer mean that.
        self::assertSame([], $reparsed['paths']['/widgets']['get']['security']);
    }

    public function testStatusCodeKeysStayStringsAcrossTheYamlRoundTrip(): void
    {
        $this->writeRoot();
        $this->write('paths/widgets.yaml', [
            'paths' => ['/widgets' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]]],
        ]);

        $yaml = SpecificationBundler::toYaml($this->bundler()->bundle());

        self::assertStringContainsString("'200':", $yaml);
    }

    public function testAMissingRootIsReportedRatherThanIgnored(): void
    {
        $this->expectException(ContractBuildError::class);
        $this->expectExceptionMessageMatches('/openapi\.yaml does not exist/');

        $this->bundler()->sourceFiles();
    }

    public function testBrokenYamlNamesTheFile(): void
    {
        $this->writeRoot();
        file_put_contents($this->directory . '/paths/widgets.yaml', "paths:\n  - : broken\n\t- indeed");

        $this->expectException(ContractBuildError::class);
        $this->expectExceptionMessageMatches('/paths\/widgets\.yaml is not valid YAML/');

        $this->bundler()->bundle();
    }

    private function bundler(): SpecificationBundler
    {
        return new SpecificationBundler($this->directory);
    }

    private function writeRoot(): void
    {
        $this->write('openapi.yaml', [
            'openapi' => '3.0.3',
            'info' => ['title' => 'fixture', 'version' => '1.0.0'],
            'servers' => [['url' => '/api/v1']],
            'paths' => [],
            'components' => ['schemas' => ['Problem' => ['type' => 'object']]],
        ]);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function write(string $relative, array $content): void
    {
        file_put_contents($this->directory . '/' . $relative, Yaml::dump($content, 512, 2));
    }
}
