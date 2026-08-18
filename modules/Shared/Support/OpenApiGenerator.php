<?php

declare(strict_types=1);

namespace Modules\Shared\Support;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Modules\Shared\Application\ClientChannel;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * Builds the OpenAPI 3.1 document from the running application (ADR 0038).
 *
 * **GENERATED, NEVER HAND-WRITTEN**, and that is the whole decision.
 *
 * A hand-written specification is a second description of the system, and the two descriptions
 * drift starting the day after the first one is written. Not because anybody is careless — because
 * the change that breaks the document is a change to a *response shape*, and nothing about editing
 * a projection method suggests opening a YAML file. Six months later the document is confidently
 * wrong, which is worse than absent: a frontend developer builds against it and discovers the
 * divergence at integration.
 *
 * So every part of this document that CAN come from the code does:
 *
 *  * **paths and methods** from the router, so an endpoint cannot exist undocumented;
 *  * **path parameters** from the route URI;
 *  * **auth and audience** from the middleware and from `CitizenSurface`;
 *  * **enums from the PHP enums themselves** — which is what makes the acceptance criterion
 *    "documented enums match actual backend output" structural rather than a promise. The document
 *    cannot describe a status the backend does not have, because it reads the same `cases()` the
 *    backend serialises from;
 *  * **error responses** from `ErrorCode`, so a new code appears in every operation at once.
 *
 * What cannot come from the code is prose: a summary of what an endpoint is *for*, and an example
 * a human wrote. Those live in `docs/api/annotations.php`, keyed by route name — a small file whose
 * staleness is visible, rather than a large one whose staleness is not.
 *
 * THE DOCUMENT IS COMMITTED, AND A TEST FAILS IF IT IS STALE. That is what turns "breaking response
 * changes require a version/deprecation decision" into something the build enforces: a change to a
 * response shape produces a diff in `openapi.json`, in the same commit, where a reviewer sees it.
 */
final class OpenApiGenerator
{
    /**
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Taytay, Rizal LGU IDS — Backend API',
                'version' => (string) config('api.version', 'v1'),
                'summary' => 'Identity and social-welfare services for the Municipality of Taytay, Rizal.',
                'description' => $this->preamble(),
            ],
            'servers' => [
                // Placeholders. Real hostnames are a deployment fact and are not committed here
                // (Article 5.6, and the deployment topology is not this document's business).
                ['url' => 'https://api.<approved-domain>/api/v1', 'description' => 'Production'],
                ['url' => 'https://api-staging.<approved-domain>/api/v1', 'description' => 'Staging'],
            ],
            'tags' => $this->tags(),
            'paths' => $this->paths(),
            'components' => [
                'securitySchemes' => [
                    'bearer' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'A first-party Sanctum token (ADR 0005). Cookie/session '
                            .'authentication is deliberately not used.',
                    ],
                ],
                'schemas' => $this->schemas(),
                'responses' => $this->errorResponses(),
                'parameters' => $this->commonParameters(),
            ],
        ];
    }

    /**
     * The conventions a client needs before reading a single path.
     */
    private function preamble(): string
    {
        return <<<'MD'
            Every response uses one envelope: `{ "data": ..., "meta": { ... } }` for success and
            `{ "error": { "code", "message", "details", "request_id" } }` for failure. `code` is a
            stable `SCREAMING_SNAKE_CASE` string — **branch on it, never on `message`**, which is
            written for operators and may be reworded.

            Every response carries `X-Request-Id`, echoed inside error payloads. A citizen can quote
            it to a support desk and it will match the audit trail and the server logs.

            Collections are always paginated. Timestamps are ISO-8601 UTC. Money is **integer
            centavos** with an explicit `currency`, never a decimal. Identifiers exposed to clients
            are UUIDs.

            `X-Client-Channel` is telemetry: it is recorded for audit and may pick a default page
            size, and it never grants or widens permission.

            A retryable write accepts `Idempotency-Key`. Same key and same body replays the stored
            response verbatim; same key with a different body is a `409`.

            **A `404` on a record you do not own is deliberate.** A `403` would confirm the
            identifier names something real, which is most of what an enumeration attempt wants.
            Function-level refusals answer `403`, because the existence of an endpoint is not a
            secret.
            MD;
    }

    /**
     * @return list<array<string, string>>
     */
    private function tags(): array
    {
        return [
            ['name' => 'citizen', 'description' => 'Reachable by a resident acting for themselves.'],
            ['name' => 'staff', 'description' => 'Requires a server-resolved permission. The `admin/` prefix grants nothing.'],
            ['name' => 'public', 'description' => 'No authentication. Each is an affirmative choice recorded in its route file.'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paths(): array
    {
        $annotations = $this->annotations();
        $citizenRoutes = CitizenSurface::citizenRouteNames();
        $paths = [];

        foreach ($this->apiRoutes() as $route) {
            $name = (string) $route->getName();
            $uri = '/'.preg_replace('#^api/v1/?#', '', $route->uri());
            $uri = $uri === '/' ? '/' : rtrim($uri, '/');

            $note = $annotations[$name] ?? [];
            $requiresAuth = $this->requiresAuth($route);
            $isCitizen = in_array($name, $citizenRoutes, true);

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $paths[$uri][strtolower($method)] = array_filter([
                    'operationId' => $name,
                    'tags' => [$requiresAuth ? ($isCitizen ? 'citizen' : 'staff') : 'public'],
                    'summary' => $note['summary'] ?? $this->inferSummary($name),
                    'description' => $note['description'] ?? null,
                    'security' => $requiresAuth ? [['bearer' => []]] : [],
                    'parameters' => $this->parametersFor($route, $note),
                    'responses' => $this->responsesFor($method, $note),
                    /*
                     * The permission, published. A frontend that knows which permission an endpoint
                     * costs can hide a button the server would refuse — while remembering that
                     * hiding a button is not access control (Article 3.4).
                     */
                    'x-permission' => $note['permission'] ?? null,
                    'x-rate-limit' => $this->rateLimitFor($route),
                ], static fn (mixed $value): bool => $value !== null && $value !== []);
            }
        }

        ksort($paths);

        return $paths;
    }

    /**
     * @return array<string, mixed>
     */
    private function schemas(): array
    {
        $schemas = [
            'Error' => [
                'type' => 'object',
                'required' => ['error'],
                'properties' => [
                    'error' => [
                        'type' => 'object',
                        'required' => ['code', 'message'],
                        'properties' => [
                            'code' => [
                                'type' => 'string',
                                /*
                                 * THE BACKING VALUE, NOT THE CASE NAME.
                                 *
                                 * `ApiResponse::error()` puts `$code->value` on the wire —
                                 * `VALIDATION_FAILED` — so publishing `$code->name`
                                 * (`ValidationFailed`) described an API that has never existed.
                                 * Every client that did what this document instructs, and
                                 * branched on `code`, matched nothing and silently never fired.
                                 *
                                 * The domain enums thirty lines below always used `->value`.
                                 * ErrorCode was the sole exception, in both generators.
                                 */
                                'enum' => array_map(
                                    static fn (ErrorCode $code): string => $code->value,
                                    ErrorCode::cases(),
                                ),
                                'description' => 'Stable and machine-readable. Branch on this.',
                            ],
                            'message' => ['type' => 'string', 'description' => 'For operators. May be reworded; never branch on it.'],
                            'details' => ['type' => 'object', 'additionalProperties' => true],
                            'request_id' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            /*
             * THE PAGINATION CONTRACT, PUBLISHED RATHER THAN DESCRIBED IN PROSE.
             *
             * These five keys are exactly what `Page::meta()` builds and `ApiResponse::page()`
             * puts under `meta.pagination`. `has_more` was served but never published, and the
             * schema itself was referenced by nothing — `meta` was a bare `{"type":"object"}`,
             * so a client generating from this document received an opaque object and had to
             * read conventions.md §3 to learn the shape. That is how a consumer ends up
             * inventing `meta.pageSize` and discovering the truth at runtime.
             */
            'Pagination' => [
                'type' => 'object',
                'required' => ['page', 'per_page', 'total', 'total_pages', 'has_more'],
                'properties' => [
                    'page' => ['type' => 'integer', 'minimum' => 1, 'description' => '1-based. A page beyond the end returns 200 with an empty data array.'],
                    'per_page' => ['type' => 'integer', 'description' => 'Default 25, maximum 100. Out-of-range values are clamped, never rejected.'],
                    'total' => ['type' => 'integer'],
                    'total_pages' => ['type' => 'integer'],
                    'has_more' => ['type' => 'boolean'],
                ],
            ],
            'Meta' => [
                'type' => 'object',
                'required' => ['request_id'],
                'properties' => [
                    'request_id' => ['type' => 'string', 'description' => 'Matches the X-Request-Id response header. Show it in a failure notice so a caseworker can quote it.'],
                    'pagination' => ['$ref' => '#/components/schemas/Pagination'],
                ],
                'description' => 'Additive. Clients must tolerate new keys.',
            ],
            'PaginatedMeta' => [
                'allOf' => [
                    ['$ref' => '#/components/schemas/Meta'],
                    ['type' => 'object', 'required' => ['pagination']],
                ],
                'description' => 'The meta of a collection response. Collections are always paginated.',
            ],
        ];

        /*
         * EVERY DOMAIN ENUM, READ FROM THE ENUM ITSELF.
         *
         * This is what makes "documented enums match actual backend output" a property rather than
         * a promise: the document cannot describe a status the backend does not have, because both
         * come from the same `cases()`.
         */
        foreach ($this->domainEnums() as $name => $class) {
            $schemas[$name] = [
                'type' => 'string',
                'enum' => array_map(static fn (\BackedEnum $case): string|int => $case->value, $class::cases()),
                'description' => $this->firstDocLine($class),
            ];
        }

        ksort($schemas);

        return $schemas;
    }

    /**
     * Every backed enum a client can observe in a response.
     *
     * Discovered from the filesystem rather than listed, so an enum added next year is documented
     * without anybody remembering to add it — the same reasoning as the queued-job scan in
     * ADR 0036 §5.
     *
     * @return array<string, class-string<\BackedEnum>>
     */
    public function domainEnums(): array
    {
        $enums = [];

        /** @var iterable<\SplFileInfo> $iterator */
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('modules')));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            // Domain and Contracts only. An enum in Infrastructure is a persistence detail.
            if (! str_contains($path, '/Domain/') && ! str_contains($path, '/Contracts/')) {
                continue;
            }

            if (preg_match('#/modules/([^/]+)/(?:Domain|Contracts)/(\w+)\.php$#', $path, $matches) !== 1) {
                continue;
            }

            $class = 'Modules\\'.$matches[1].'\\'.(str_contains($path, '/Domain/') ? 'Domain' : 'Contracts').'\\'.$matches[2];

            if (! enum_exists($class) || ! is_subclass_of($class, \BackedEnum::class)) {
                continue;
            }

            // Prefixed by module, because two modules legitimately have a `Status`.
            $enums[$matches[1].$matches[2]] = $class;
        }

        ksort($enums);

        return $enums;
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponses(): array
    {
        $responses = [];

        foreach (ErrorCode::cases() as $code) {
            $status = (string) $code->httpStatus();

            // One response component per status, named by the first code that uses it.
            $responses[$code->name] ??= [
                'description' => $code->defaultMessage(),
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
            ];

            unset($status);
        }

        return $responses;
    }

    /**
     * @return array<string, mixed>
     */
    private function commonParameters(): array
    {
        return [
            'page' => ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
            'per_page' => ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
            'ClientChannel' => [
                'name' => 'X-Client-Channel',
                'in' => 'header',
                'schema' => ['type' => 'string', 'enum' => array_column(ClientChannel::cases(), 'value')],
                'description' => 'Telemetry and presentation defaults only. Grants nothing.',
            ],
            'IdempotencyKey' => [
                'name' => 'Idempotency-Key',
                'in' => 'header',
                'schema' => ['type' => 'string', 'maxLength' => 128],
                'description' => 'Opt-in replay protection on a retryable write.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $note
     * @return list<array<string, mixed>>
     */
    private function parametersFor(Route $route, array $note): array
    {
        $parameters = [];

        foreach ($route->parameterNames() as $parameter) {
            $parameters[] = [
                'name' => $parameter,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
                'description' => $note['params'][$parameter] ?? 'A UUID, unless the endpoint says otherwise.',
            ];
        }

        if (($note['paginated'] ?? false) === true) {
            $parameters[] = ['$ref' => '#/components/parameters/page'];
            $parameters[] = ['$ref' => '#/components/parameters/per_page'];
        }

        return $parameters;
    }

    /**
     * @param  array<string, mixed>  $note
     * @return array<string, mixed>
     */
    private function responsesFor(string $method, array $note): array
    {
        $success = (string) ($note['status'] ?? ($method === 'POST' ? '201' : ($method === 'DELETE' ? '200' : '200')));

        $paginated = ($note['paginated'] ?? false) === true;

        $responses = [
            $success => [
                'description' => $note['returns'] ?? 'Success.',
                'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'required' => ['data', 'meta'],
                    'properties' => [
                        'data' => $paginated
                            ? ['type' => 'array', 'items' => ['description' => $note['returns'] ?? 'A member of the collection.']]
                            : ['description' => $note['returns'] ?? 'The resource.'],
                        /*
                         * A REFERENCE, NOT A BARE OBJECT. A consumer generating from this
                         * document now receives `meta.request_id` and, on a paginated
                         * endpoint, a required `meta.pagination` — rather than an untyped
                         * object it has to guess at.
                         */
                        'meta' => ['$ref' => $paginated
                            ? '#/components/schemas/PaginatedMeta'
                            : '#/components/schemas/Meta'],
                    ],
                ]]],
            ],
        ];

        /*
         * EVERY OPERATION DOCUMENTS THE SAME FAILURES, because every operation can produce them —
         * the renderer is global (ADR 0003). A client written against one endpoint's error
         * handling works for all of them, which is the point of a single envelope.
         */
        foreach (['Unauthenticated', 'Forbidden', 'NotFound', 'ValidationFailed', 'Conflict', 'RateLimited', 'ServerError'] as $code) {
            $responses[(string) ErrorCode::{$code}->httpStatus()] = ['$ref' => '#/components/responses/'.$code];
        }

        return $responses;
    }

    /**
     * The named rate limiter, if any. Same alias-or-class problem as `requiresAuth()`.
     */
    private function rateLimitFor(Route $route): ?string
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if (str_starts_with($middleware, 'throttle:') || str_contains($middleware, 'ThrottleRequests:')) {
                return explode(':', $middleware)[1] ?? null;
            }
        }

        return null;
    }

    /**
     * Whether this route sits behind authentication.
     *
     * MATCHES BOTH THE ALIAS AND THE RESOLVED CLASS, because `gatherMiddleware()` returns the
     * alias (`auth:sanctum`) while `route:list` prints the resolved class
     * (`Illuminate\Auth\Middleware\Authenticate:sanctum`). The first version of this method
     * checked only the class name and quietly tagged **every authenticated endpoint in the API as
     * `public`** — a document that is confidently wrong, which is worse than one that is absent,
     * because a frontend developer builds against it.
     *
     * `OpenApiTest::no_authenticated_route_is_documented_as_public` is what caught it and is what
     * stops it recurring.
     */
    private function requiresAuth(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if (str_starts_with($middleware, 'auth:') || str_contains($middleware, 'Middleware\Authenticate')) {
                return true;
            }
        }

        return false;
    }

    private function inferSummary(string $routeName): string
    {
        return ucfirst(str_replace(['v1.', '.', '-'], ['', ' ', ' '], $routeName));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function annotations(): array
    {
        $path = base_path('docs/api/annotations.php');

        return is_file($path) ? (array) require $path : [];
    }

    /**
     * @return list<Route>
     */
    private function apiRoutes(): array
    {
        $routes = [];

        foreach (Router::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1') && $route->getName() !== null) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    /**
     * @param  class-string  $class
     */
    private function firstDocLine(string $class): string
    {
        $doc = (new \ReflectionClass($class))->getDocComment();

        if ($doc === false) {
            return '';
        }

        foreach (explode("\n", $doc) as $line) {
            $clean = trim(str_replace(['/**', '*/', '*'], '', $line));

            if ($clean !== '') {
                return $clean;
            }
        }

        return '';
    }
}
