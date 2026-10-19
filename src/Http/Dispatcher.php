<?php

declare(strict_types=1);

namespace Mailadmin\Http;

use FastRoute\Dispatcher as FastRouteDispatcher;
use FastRoute\RouteCollector;

use function FastRoute\simpleDispatcher;

use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\Middleware\OpenApiValidationMiddleware;
use Mailadmin\Http\Middleware\RequestIdMiddleware;
use Mailadmin\Http\Problem\ErrorCode;
use Mailadmin\Http\Problem\ProblemDetails;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Mailadmin\Http\Routing\BasePath;
use Mailadmin\Http\Routing\ContractArtefacts;
use Mailadmin\Http\Routing\DocumentationEndpoint;
use Mailadmin\Http\Routing\Route;
use Mailadmin\Http\Routing\StaleBuildError;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * The innermost handler: contract route table in, handler call out.
 *
 * It is the only place that decides what a path means, and it decides it from
 * the compiled contract — there is no second way to register a route
 * (`CLAUDE.md`, rule three).
 *
 * What it does, in order:
 *
 *  1. Removes the mount prefix, so the contract never sees deployment detail.
 *  2. Serves `/api/docs` if that is switched on (U-9). Not a contract path;
 *     see {@see DocumentationEndpoint}.
 *  3. Loads the route table. If the build artefacts do not match the contract
 *     beside them, the answer is `503` with `migration.build_stale` (U-8) —
 *     refusing service beats serving a route table that describes something
 *     other than what the code does.
 *  4. Matches. No path is `404`, wrong method is `405` with `Allow`.
 *  5. Refuses to run a handler for a request that schema validation did not
 *     mark. That mark is the input boundary; a handler must never be reachable
 *     around it, and a silent gap would be exactly the kind of bug nobody
 *     notices until it is exploited.
 *  6. Puts `operationId`, the declared `x-permission` and the path parameters
 *     into request attributes and runs the **route middleware** — the chain that
 *     needs to know which operation was matched — before the handler.
 *
 * ## Why there are two middleware chains
 *
 * The outer chain in `config/di/core.php` runs before anything is routed, which
 * is right for request ids, security headers and JSON decoding: they apply to
 * every response, including the ones produced when no route matches at all.
 *
 * Authentication and authorisation cannot live there. Whether a token is
 * required is a property of the matched operation — `x-permission` and
 * `security: []` in the contract — and nothing before the router knows which
 * operation that is. A middleware placed outside would have to guess, and the
 * two available guesses are both wrong: demand a token everywhere and
 * `POST /auth/token` becomes unreachable; demand it nowhere and enforcement
 * moves into each handler, which is where the legacy system kept it.
 *
 * So they run here, with the attributes already set. S2 puts authentication in
 * this slot; S3 adds authorisation behind it.
 */
final class Dispatcher implements RequestHandlerInterface
{
    /** The `operationId` of the matched operation. */
    public const string ATTRIBUTE_OPERATION_ID = 'mailadmin.operation_id';

    /** The operation's `x-permission` value, verbatim from the contract. S3 enforces it. */
    public const string ATTRIBUTE_PERMISSION = 'mailadmin.permission';

    /**
     * The path parameters as `array<string, string>`. Each one is additionally
     * set as an attribute under its own name, which is what a handler usually
     * reads; the map exists for anything that needs them together.
     */
    public const string ATTRIBUTE_PATH_PARAMETERS = 'mailadmin.path_parameters';

    private ?FastRouteDispatcher $router = null;

    /** @var list<MiddlewareInterface> */
    private readonly array $routeMiddleware;

    /**
     * @param list<MiddlewareInterface> $routeMiddleware runs after the route is
     *                                                   matched, outermost first
     */
    public function __construct(
        private readonly ContractArtefacts $artefacts,
        private readonly ContainerInterface $handlers,
        private readonly ProblemResponseFactory $problems,
        private readonly NotFoundHandler $notFound,
        private readonly LoggerInterface $logger,
        private readonly DocumentationEndpoint $documentation,
        private readonly string $basePath,
        private readonly bool $requireValidatedRequests,
        array $routeMiddleware = [],
    ) {
        $this->routeMiddleware = array_values($routeMiddleware);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = BasePath::strip($request->getUri()->getPath(), $this->basePath);

        try {
            if ($this->documentation->matches($path)) {
                return $this->documentation->respond($path);
            }

            $router = $this->router();
        } catch (StaleBuildError $error) {
            return $this->staleBuild($request, $error);
        }

        $result = $router->dispatch(strtoupper($request->getMethod()), $path);

        return match ($result[0]) {
            FastRouteDispatcher::FOUND => $this->run($request, $result[1], $result[2]),
            FastRouteDispatcher::METHOD_NOT_ALLOWED => $this->methodNotAllowed($request, $result[1]),
            default => $this->notFound->handle($request),
        };
    }

    /**
     * @param mixed $matched the route FastRoute returned — narrowed here rather than trusted
     * @param mixed $parameters
     */
    private function run(ServerRequestInterface $request, mixed $matched, mixed $parameters): ResponseInterface
    {
        if (!$matched instanceof Route) {
            throw new \LogicException('The route table produced something other than a Route.');
        }

        if ($this->requireValidatedRequests) {
            $mark = $request->getAttribute(OpenApiValidationMiddleware::ATTRIBUTE);

            if ($mark !== $matched->validationMark()) {
                // Not a client error: the chain is wired wrongly, or the
                // validator and the router disagree about which operation this
                // is. Either way the request has not passed the input boundary,
                // so it does not reach a handler.
                throw new \LogicException(sprintf(
                    'Operation "%s" was routed without passing schema validation. '
                    . 'OpenApiValidationMiddleware must run in front of the dispatcher.',
                    $matched->operationId,
                ));
            }
        }

        $handler = $this->handlers->get($matched->handler);

        if (!$handler instanceof ApiHandler) {
            throw new \LogicException(sprintf(
                'The container produced %s for operation "%s", which is not an %s.',
                get_debug_type($handler),
                $matched->operationId,
                ApiHandler::class,
            ));
        }

        $pathParameters = [];

        if (is_array($parameters)) {
            /** @var mixed $value */
            foreach ($parameters as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $pathParameters[$name] = $value;
                    $request = $request->withAttribute($name, $value);
                }
            }
        }

        $request = $request
            ->withAttribute(self::ATTRIBUTE_OPERATION_ID, $matched->operationId)
            ->withAttribute(self::ATTRIBUTE_PERMISSION, $matched->permission)
            ->withAttribute(self::ATTRIBUTE_PATH_PARAMETERS, $pathParameters);

        if ($this->routeMiddleware === []) {
            return $handler->handle($request);
        }

        return (new MiddlewareDispatcher($handler, ...$this->routeMiddleware))->handle($request);
    }

    /**
     * @param mixed $allowed the methods FastRoute reported for this path
     */
    private function methodNotAllowed(ServerRequestInterface $request, mixed $allowed): ResponseInterface
    {
        $methods = [];

        if (is_array($allowed)) {
            /** @var mixed $method */
            foreach ($allowed as $method) {
                if (is_string($method)) {
                    $methods[] = strtoupper($method);
                }
            }
        }

        sort($methods);

        $response = $this->problems->create(new ProblemDetails(
            status: 405,
            code: ErrorCode::METHOD_NOT_ALLOWED,
            title: 'Method Not Allowed',
            detail: 'This path does not accept the request method.',
            instance: $request->getUri()->getPath(),
            requestId: $this->requestId($request),
        ));

        return $methods === [] ? $response : $response->withHeader('Allow', implode(', ', $methods));
    }

    private function staleBuild(ServerRequestInterface $request, StaleBuildError $error): ResponseInterface
    {
        // The message names files and build steps, never a secret — but it stays
        // in the log all the same. The client gets the code and the request id.
        $this->logger->critical('Refusing to serve: the compiled contract is stale.', [
            'reason' => $error->getMessage(),
            'http_path' => $request->getUri()->getPath(),
        ]);

        return $this->problems
            ->create(new ProblemDetails(
                status: 503,
                code: ErrorCode::BUILD_STALE,
                title: 'Service Unavailable',
                detail: 'The service is not correctly deployed and is not answering requests.',
                instance: $request->getUri()->getPath(),
                requestId: $this->requestId($request),
            ))
            ->withHeader('Retry-After', '30');
    }

    /**
     * FastRoute's data is generated per instance rather than cached to a file:
     * the route table is a few dozen entries, generating the group regexes costs
     * microseconds, and a second cache file would be a second thing that can go
     * stale. The dispatcher itself is a container singleton, so it happens once
     * per request at most.
     */
    private function router(): FastRouteDispatcher
    {
        if ($this->router !== null) {
            return $this->router;
        }

        $routes = $this->artefacts->routeTable()->routes;

        return $this->router = simpleDispatcher(static function (RouteCollector $collector) use ($routes): void {
            foreach ($routes as $route) {
                $collector->addRoute($route->method, $route->path, $route);
            }
        });
    }

    private function requestId(ServerRequestInterface $request): ?string
    {
        $value = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($value) ? $value : null;
    }
}
