<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Http\Routing;

use Mailadmin\Http\Routing\ContractBuildError;
use Mailadmin\Http\Routing\RouteCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The build-time guarantee: what the contract describes and what the code can
 * answer are the same set, or there is no build (U-4).
 */
#[CoversClass(RouteCompiler::class)]
final class RouteCompilerTest extends TestCase
{
    private const string HANDLER_NAMESPACE = 'Mailadmin\\Tests\\Unit\\Http\\Routing\\Fixtures\\Handler';

    public function testCompilesAnOperationOntoTheHandlerTheConventionNames(): void
    {
        $table = $this->compiler()->compile($this->contract(), 'spec-hash', 'source-hash');

        self::assertSame('spec-hash', $table->specChecksum);
        self::assertSame('source-hash', $table->sourceChecksum);
        self::assertSame('/api/v1', $table->basePath);
        self::assertCount(2, $table->routes);

        $byOperation = [];
        foreach ($table->routes as $route) {
            $byOperation[$route->operationId] = $route;
        }

        self::assertSame('GET', $byOperation['listWidgets']->method);
        self::assertSame('/api/v1/widgets', $byOperation['listWidgets']->path);
        self::assertSame('/widgets', $byOperation['listWidgets']->specPath);
        self::assertSame(self::HANDLER_NAMESPACE . '\\Widgets\\ListWidgetsHandler', $byOperation['listWidgets']->handler);
        self::assertSame('domain.read', $byOperation['listWidgets']->permission);
        self::assertSame('widgets', $byOperation['listWidgets']->tag);

        self::assertSame('POST', $byOperation['createWidget']->method);
        self::assertSame(self::HANDLER_NAMESPACE . '\\Widgets\\CreateWidgetHandler', $byOperation['createWidget']->handler);
    }

    public function testTheVersionPrefixComesFromServersRatherThanFromEveryPath(): void
    {
        $contract = $this->contract();
        $contract['servers'] = [['url' => '/api/v2/']];

        $table = $this->compiler()->compile($contract, 'spec', 'source');

        self::assertSame('/api/v2', $table->basePath);
        self::assertSame('/api/v2/widgets', $table->routes[0]->path);
        self::assertSame('/widgets', $table->routes[0]->specPath);
    }

    public function testAnOperationWithoutAHandlerFailsTheBuildAndNamesTheExpectedClass(): void
    {
        $contract = $this->contract();
        $contract['paths']['/widgets']['get']['operationId'] = 'countWidgets';

        $this->expectException(ContractBuildError::class);
        $this->expectExceptionMessageMatches('/countWidgets/');
        $this->expectExceptionMessageMatches('/Widgets\\\\CountWidgetsHandler/');

        $this->compiler()->compile($contract, 'spec', 'source');
    }

    public function testAHandlerWithoutAnOperationFailsTheBuild(): void
    {
        $contract = $this->contract();
        unset($contract['paths']['/widgets']['post']);

        $this->expectException(ContractBuildError::class);
        $this->expectExceptionMessageMatches('/CreateWidgetHandler/');

        $this->compiler()->compile($contract, 'spec', 'source');
    }

    public function testAClassThatIsNotAnApiHandlerIsNotAcceptedEvenWithTheRightName(): void
    {
        $compiler = new RouteCompiler(
            __DIR__ . '/Fixtures/Misnamed',
            'Mailadmin\\Tests\\Unit\\Http\\Routing\\Fixtures\\Misnamed',
        );

        $contract = $this->contract();
        unset($contract['paths']['/widgets']['post']);

        $this->expectException(ContractBuildError::class);
        $this->expectExceptionMessageMatches('/does not implement/');

        $compiler->compile($contract, 'spec', 'source');
    }

    public function testAnOperationWithoutXPermissionFailsTheBuild(): void
    {
        $contract = $this->contract();
        unset($contract['paths']['/widgets']['get']['x-permission']);

        $this->expectException(ContractBuildError::class);
        $this->expectExceptionMessageMatches('/x-permission/');
        $this->expectExceptionMessageMatches('/listWidgets/');

        $this->compiler()->compile($contract, 'spec', 'source');
    }

    public function testAnEmptyXPermissionCountsAsMissing(): void
    {
        $contract = $this->contract();
        $contract['paths']['/widgets']['get']['x-permission'] = '   ';

        $this->expectException(ContractBuildError::class);
        $this->expectExceptionMessageMatches('/x-permission/');

        $this->compiler()->compile($contract, 'spec', 'source');
    }

    public function testAnXPermissionTheCodeDoesNotKnowFailsTheBuild(): void
    {
        // S3. A misspelled permission is never granted to anyone, so without
        // this the contract would compile into an operation that refuses
        // everybody — findable only by calling it.
        $contract = $this->contract();
        $contract['paths']['/widgets']['get']['x-permission'] = 'domain.raed';

        $this->expectException(ContractBuildError::class);
        $this->expectExceptionMessageMatches('/domain\.raed/');
        $this->expectExceptionMessageMatches('/Permission/');

        $this->compiler()->compile($contract, 'spec', 'source');
    }

    public function testThePublicMarkerIsAcceptedAsAPermission(): void
    {
        // `public` is a case of the enum precisely so that this needs no special
        // case here — one list of legal values, one lookup.
        $contract = $this->contract();
        $contract['paths']['/widgets']['get']['x-permission'] = 'public';

        $table = $this->compiler()->compile($contract, 'spec', 'source');

        $permissions = [];

        foreach ($table->routes as $route) {
            $permissions[$route->operationId] = $route->permission;
        }

        self::assertSame('public', $permissions['listWidgets']);
    }

    public function testADuplicateOperationIdFailsTheBuild(): void
    {
        $contract = $this->contract();
        $contract['paths']['/widgets']['post']['operationId'] = 'listWidgets';

        $this->expectException(ContractBuildError::class);
        $this->expectExceptionMessageMatches('/used twice/');
        $this->expectExceptionMessageMatches('/listWidgets/');

        $this->compiler()->compile($contract, 'spec', 'source');
    }

    public function testAnOperationWithoutTagsFailsTheBuildBecauseTheNamespaceComesFromThere(): void
    {
        $contract = $this->contract();
        unset($contract['paths']['/widgets']['get']['tags']);

        $this->expectException(ContractBuildError::class);
        $this->expectExceptionMessageMatches('/tags/');

        $this->compiler()->compile($contract, 'spec', 'source');
    }

    public function testAnOperationIdThatIsNotLowerCamelCaseFailsTheBuild(): void
    {
        $contract = $this->contract();
        $contract['paths']['/widgets']['get']['operationId'] = 'list_widgets';

        $this->expectException(ContractBuildError::class);
        $this->expectExceptionMessageMatches('/lowerCamelCase/');

        $this->compiler()->compile($contract, 'spec', 'source');
    }

    public function testOpenApiThreeOneIsRefused(): void
    {
        $contract = $this->contract();
        $contract['openapi'] = '3.1.0';

        $this->expectException(ContractBuildError::class);
        $this->expectExceptionMessageMatches('/U-2/');

        $this->compiler()->compile($contract, 'spec', 'source');
    }

    public function testAPathParameterThatCouldBeReadAsARoutePatternIsRefused(): void
    {
        $contract = $this->contract();
        $contract['paths']['/widgets/{id:\d+}'] = $contract['paths']['/widgets'];
        unset($contract['paths']['/widgets']);

        $this->expectException(ContractBuildError::class);
        $this->expectExceptionMessageMatches('/parameter name/');

        $this->compiler()->compile($contract, 'spec', 'source');
    }

    public function testAPathParameterBecomesPartOfTheRoutablePath(): void
    {
        $contract = $this->contract();
        $contract['paths']['/widgets/{widgetId}'] = ['post' => $contract['paths']['/widgets']['post']];
        unset($contract['paths']['/widgets']['post']);

        $table = $this->compiler()->compile($contract, 'spec', 'source');

        $paths = array_map(static fn (object $route): string => (string) $route->path, $table->routes);
        self::assertContains('/api/v1/widgets/{widgetId}', $paths);
    }

    public function testTheTableSurvivesBeingWrittenAsPhpAndReadBack(): void
    {
        $table = $this->compiler()->compile($this->contract(), 'spec', 'source');

        $file = tempnam(sys_get_temp_dir(), 'routes') . '.php';
        file_put_contents($file, $table->toPhpSource());

        /** @var array<string, mixed> $data */
        $data = require $file;
        unlink($file);

        self::assertEquals($table, \Mailadmin\Http\Routing\RouteTable::fromArray($data));
    }

    private function compiler(): RouteCompiler
    {
        return new RouteCompiler(__DIR__ . '/Fixtures/Handler', self::HANDLER_NAMESPACE);
    }

    /**
     * A minimal but complete contract: two operations on one path, both with a
     * handler in the fixtures.
     *
     * @return array<string, mixed>
     */
    private function contract(): array
    {
        return [
            'openapi' => '3.0.3',
            'servers' => [['url' => '/api/v1']],
            'paths' => [
                '/widgets' => [
                    'summary' => 'Not an operation — a path-level field.',
                    'get' => [
                        'operationId' => 'listWidgets',
                        'tags' => ['widgets'],
                        'x-permission' => 'domain.read',
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                    'post' => [
                        'operationId' => 'createWidget',
                        'tags' => ['widgets'],
                        'x-permission' => 'domain.write',
                        'responses' => ['201' => ['description' => 'created']],
                    ],
                ],
            ],
        ];
    }
}
