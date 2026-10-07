# mezzio-valinor-request-mapper
[![Latest Stable Version](http://poser.pugx.org/sirix/mezzio-valinor-request-mapper/v)](https://packagist.org/packages/sirix/mezzio-valinor-request-mapper) [![Total Downloads](http://poser.pugx.org/sirix/mezzio-valinor-request-mapper/downloads)](https://packagist.org/packages/sirix/mezzio-valinor-request-mapper) [![Latest Unstable Version](http://poser.pugx.org/sirix/mezzio-valinor-request-mapper/v/unstable)](https://packagist.org/packages/sirix/mezzio-valinor-request-mapper) [![License](http://poser.pugx.org/sirix/mezzio-valinor-request-mapper/license)](https://packagist.org/packages/sirix/mezzio-valinor-request-mapper) [![PHP Version Require](http://poser.pugx.org/sirix/mezzio-valinor-request-mapper/require/php)](https://packagist.org/packages/sirix/mezzio-valinor-request-mapper)

Typed request mapping for Mezzio handlers via `cuyz/valinor`.

This package reads `#[MapRequest]` attributes on route handlers and maps request input (body/query/route) into DTOs before your handler code runs.

## Features

- `#[MapRequest]` attribute for class and method targets (repeatable)
- Mapping from:
  - parsed body (`body`)
  - query params (`query`)
  - route params (`route`)
  - combined HTTP request (`source`) using Valinor HTTP attributes (`FromBody`, `FromQuery`, `FromRoute`)
- Optional request attribute key override via `output`
- HTTP method filter via `methods` (case-insensitive, normalized to uppercase)
- Pluggable error responders for mapping failures
- Built-in JSON fallback with a fixed `422` response contract

## Requirements

- PHP `~8.2 || ~8.3 || ~8.4 || ~8.5`
- `cuyz/valinor ^2.4`
- `mezzio/mezzio-router ^3.15 || ^4.1`
- PSR-17 `ResponseFactoryInterface` and `StreamFactoryInterface` services
- `sirix/mezzio-routing-contracts ^1.2`

The package targets Mezzio applications, but does not install a specific
Mezzio, PSR-7, or PSR-17 implementation. Your application provides those
runtime dependencies; the default responder uses its PSR-17 factories.

## Installation

```bash
composer require sirix/mezzio-valinor-request-mapper
```

### Required service registration

`ConfigProvider` is required. It registers the `MapperBuilder`, `TreeMapper`,
default responder, middleware, and the internal services that discover route
metadata, build mapping plans, and construct Valinor HTTP input. Obtain the
middleware from the container; manually constructing it requires all four of
its dependencies and is intended only for custom integration.

In a standard Mezzio application it is discovered automatically by
`laminas/laminas-component-installer`.

If your application configures providers manually, add it to the
`ConfigAggregator`:

```php
use Laminas\ConfigAggregator\ConfigAggregator;
use Sirix\Mezzio\Valinor\ConfigProvider as ValinorRequestMapperConfigProvider;

$aggregator = new ConfigAggregator([
    ValinorRequestMapperConfigProvider::class,
    // other providers…
]);
```

## Middleware registration modes

### 1) Standalone Mezzio (without `sirix/mezzio-routing-attributes`)

Run the application's body parser first, then route matching, then this
middleware, and finally dispatch. A body parser (for example Mezzio's
`BodyParamsMiddleware`) is an application dependency: this package neither
installs nor configures one.

```php
$app->pipe(\Mezzio\Middleware\BodyParamsMiddleware::class);
$app->pipe(\Mezzio\Router\Middleware\RouteMiddleware::class);
$app->pipe(\Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware::class);
$app->pipe(\Mezzio\Router\Middleware\DispatchMiddleware::class);
```

In standalone mode the middleware resolves `#[MapRequest]` by reflection from the
actually callable target. Class-level attributes run first, then method-level
attributes on the selected method, in PHP declaration order.

For inherited method callables, class attributes come from the called child
class and method attributes come from the inherited method. Parent class
attributes are not inherited automatically.

Supported route handler targets:

| Input | Resolved method | Notes |
|---|---|---|
| `RequestHandlerInterface` instance or FQCN | `handle` | |
| `MiddlewareInterface` instance or FQCN | `process` | Priority over `RequestHandlerInterface` when both are implemented |
| Invokable object | `__invoke` | Only when no PSR-15 interface is implemented |
| `RequestHandlerMiddleware` wrapper | `handle` of the inner handler | Ignores extra interfaces of the inner handler |
| `CallableMiddlewareDecorator` with array callable | exact array method | |
| `CallableMiddlewareDecorator` with first-class callable | real method of the called class (scope fallback) | Inherited instance/static methods keep the child class |
| `CallableMiddlewareDecorator` with invokable object | `__invoke` | |
| `CallableMiddlewareDecorator` with string `Class::method` | `Class` and `method` if callable is valid | |
| `CallableMiddlewareDecorator` with anonymous closure/function | *(no mapping)* | Attributes of the outer scope are not read |
| `LazyLoadingMiddleware` with handler FQCN | `handle` or `process` | Chosen by the declared FQCN's interfaces; unknown classes are ignored |
| `MiddlewarePipe` / pipeline | *(no mapping)* | Use explicit route options (see below) |
| alias / non-class service name | *(no mapping)* | Use explicit route options (see below) |

For aliases and pipelines the package cannot discover attributes. Provide them
explicitly via route options:

```php
$route = $app->get('/orders', 'order.handler.alias');
$route->setOptions([
    'valinor_mappings' => [
        [
            'body' => CreateOrderRequest::class,
            'output' => 'createOrder',
            'methods' => ['POST'],
        ],
    ],
]);
```

A missing `valinor_mappings` key enables reflection discovery. An explicit
empty list (`[]`) deliberately disables request mapping for that route: handler
attributes are not instantiated, input is not read or validated by this
middleware, and the mapper is not called. Other application middleware continues
to run, and the original request passes downstream.

A non-empty list replaces reflection mappings and must contain maps using only
`body`, `query`, `route`, `source`, `output`, `methods`, and `errorResponder`.
Invalid values, including `null`, strings, associative arrays instead of a list,
and invalid definitions, raise `InvalidMapRequestConfiguration`; this is a
configuration failure, not a 422 mapping response. Remove the
`valinor_mappings` key if you want reflection discovery.

Discovery reflects the **declared route handler**, not the service instance the
container may return. If you register a service under an FQCN but the container
returns a different class, attributes of the declared FQCN are used. To map
attributes of the actual instance, provide `valinor_mappings` explicitly.

### 2) With `sirix/mezzio-routing-attributes`

If your app uses `sirix/mezzio-routing-attributes ^1.4` and it scans/collects route attribute modifiers,
`MapRequest` is discovered as an `AggregatingRouteAttributeModifierInterface` implementation. Class- and method-level mappings accumulate in declaration order, while `ValinorRequestMapperMiddleware` is attached once to each matching route.

The scanner appends its mappings to route options. To deliberately disable
mapping, set `valinor_mappings` to `[]` in the final route options after scanner
assembly. An initial empty list does not suppress mappings added by the scanner.

In this mode you usually do **not** need to register
`\Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware::class`
as a global pipeline middleware.

Example (class-level + method-level attributes):

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sirix\Mezzio\Routing\Attributes\Attribute\Get;
use Sirix\Mezzio\Routing\Attributes\Attribute\Post;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;

final readonly class PaginationRequest
{
    public function __construct(public int $page = 1) {}
}

final readonly class CreateOrderRequest
{
    public function __construct(public string $name, public string $email) {}
}

#[MapRequest(query: PaginationRequest::class)]
final class OrdersHandler
{
    #[Get('/orders', name: 'orders.list')]
    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $pagination = $request->getAttribute(PaginationRequest::class);
        // ...
    }

    #[Post('/orders', name: 'orders.create')]
    #[MapRequest(body: CreateOrderRequest::class, output: 'form')]
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $form = $request->getAttribute('form');
        // ...
    }
}
```

In this setup `#[MapRequest]` contributes route middleware via routing attribute processing,
so no extra global pipeline registration is required for the mapper middleware.

## Quick start

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;

final readonly class CreateUserRequest
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}

#[MapRequest(body: CreateUserRequest::class)]
final class CreateUserHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var CreateUserRequest $dto */
        $dto = $request->getAttribute(CreateUserRequest::class);

        // use $dto ...
    }
}
```

## Attribute API

```php
final readonly class MapRequest
{
    public function __construct(
        ?string $body = null,           // Valinor target from parsed body
        ?string $query = null,          // Valinor target from query parameters
        ?string $route = null,          // Valinor target from route parameters
        ?string $source = null,         // Valinor target using all HTTP sources
        ?string $output = null,         // request attribute key; defaults to exact target string
        array $methods = [],            // HTTP method filter
        ?string $errorResponder = null, // FQCN or named responder service ID
    ) {}
}
```

Rules:

- at least one source is required; `source` is mutually exclusive with
  `body/query/route`
- source, output, and responder strings must be non-empty and have no leading
  or trailing whitespace
- targets accept Valinor type signatures, including DTO FQCNs, generic DTOs
  such as `App\GenericDto<int>`, and array shapes such as `array{page: int}`.
  Valinor interprets the type grammar during mapping; the attribute does not
  validate class existence or parse that grammar
- if `output` is omitted, the mapped value is stored under the exact target
  string (a DTO FQCN for class targets). Use explicit `output` for a friendly key
- `methods = []` means any HTTP method
- non-empty `methods` must be a list of non-empty HTTP tokens; they are
  normalized (`post`, `Post` -> `POST`)
- An explicit `errorResponder` must be registered in the container as a
  `MappingErrorResponderInterface` service; it is resolved only on a mapping or
  input error. `null` uses the application-wide/default responder
- if multiple `#[MapRequest]` attributes match current method, all of them are applied in declaration order; class-level mappings run before method-level mappings
- active operations must have distinct effective output keys. For example, two
  sources that map the same DTO need separate `output` values; the package no
  longer lets a later operation overwrite an earlier DTO.
- each definition is checked when `MapRequest` is constructed, including when
  route options are parsed. Multiple sources sharing one `output`, or the same
  target without `output`, are rejected even with a method filter. Multiple
  sources with different targets and no explicit `output` remain valid;
  use repeated attributes for separate explicit output keys

For an array shape, query `page=2` maps to `['page' => 2]` at `payload`:

```php
#[MapRequest(query: 'array{page: int}', output: 'payload')]
final class PaginationHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $pagination = $request->getAttribute('payload');
        // ...
    }
}
```

Without `output`, retrieve it with `$request->getAttribute('array{page: int}')`.
Equivalent `valinor_mappings` route options accept the same target signatures.

For example, this is valid because the output keys differ:

```php
#[MapRequest(query: PaginationRequest::class, output: 'pagination')]
#[MapRequest(body: CreateOrderRequest::class, output: 'createOrder')]
final class OrdersHandler implements RequestHandlerInterface
{
    // ...
}
```

## HTTP input contract

Only the source selected by an operation is read. Body parsing and input
validation happen before Valinor receives the data; the original PSR-7 request
is never changed.

| Mapping source | Data supplied to Valinor | Preconditions |
| --- | --- | --- |
| `body` | `ServerRequestInterface::getParsedBody()` | must be `array` or `null` (`null` becomes an empty array) |
| `query` | `getQueryParams()` | parsed body is not read |
| `route` | matched route parameters | parsed body is not read |
| `source` | route, query, and body | body must be `array` or `null`; use explicit `FromBody`, `FromQuery`, and `FromRoute` attributes |

String keys and values in each selected source, including nested native arrays,
must be valid UTF-8. Invalid input produces a safe `RequestInputError` and the
default 422 response; it is not repaired or passed to Valinor. Binary payloads
belong in uploaded files or application-specific middleware instead.

With `source`, a constructor argument without a `From*` attribute is ambiguous
when the same field exists in more than one HTTP source. Valinor reports that
collision as a mapping error; specify the source explicitly rather than relying
on an implicit priority.

## Combined mapping (`source`)

Use Valinor HTTP source attributes in DTO constructor:

```php
use CuyZ\Valinor\Mapper\Http\FromBody;
use CuyZ\Valinor\Mapper\Http\FromQuery;
use CuyZ\Valinor\Mapper\Http\FromRoute;

final readonly class SearchRequest
{
    public function __construct(
        #[FromRoute] public string $locale,
        #[FromQuery] public string $q,
        #[FromBody] public ?array $filters = null,
    ) {}
}

#[MapRequest(source: SearchRequest::class)]
final class SearchHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $dto = $request->getAttribute(SearchRequest::class);
        // ...
    }
}
```

## Configuration

Create `config/autoload/mezzio-valinor.global.php`:

```php
<?php

declare(strict_types=1);

return [
    'sirix_mezzio_valinor' => [
        'mapper' => [
            'cache_dir' => __DIR__ . '/../../cache/valinor',
            'cache_watch' => false,
            'configurators' => [
                \CuyZ\Valinor\Mapper\Configurator\ConvertKeysToCamelCase::class,
            ],
            'allow_superfluous_keys' => true,
            'allow_scalar_value_casting' => true,
            'allow_permissive_types' => false,
            'allow_undefined_values' => false,
            'support_date_formats' => ['Y-m-d', 'd/m/Y'],
        ],
        'input_limits' => [
            'max_nodes' => null,
            'max_depth' => null,
            'max_total_string_bytes' => null,
        ],
        'error_response' => [
            'max_messages' => null,
            'max_response_bytes' => null,
        ],
    ],
];
```

### Input budget options

| Option | Type | Default | Description |
|---|---|---|---|
| `input_limits.max_nodes` | `?int` | `null` | Maximum number of array elements. The root array itself is not a node |
| `input_limits.max_depth` | `?int` | `null` | Maximum nesting depth of native arrays; the root array is depth 1 |
| `input_limits.max_total_string_bytes` | `?int` | `null` | Maximum total bytes of string keys and string values |

All three default to `null` (disabled); enabling one does not enable the
others. Configured limits must be positive integers. A limit is enforced in a
single iterative traversal together with UTF-8 validation, before the mapper
runs for the matching operation, and only
for the selected source (body, query or route). Exactly N nodes, depth D or B
bytes are accepted; one more is rejected with a fixed `RequestInputError`
reason (`input_node_limit_exceeded`, `input_depth_limit_exceeded`,
`input_string_bytes_limit_exceeded`, `cyclic_input`) and the default `422`
response. When limits are enabled, shared array branches are traversed once per
occurrence and circular references are rejected. With all limits disabled the
previous UTF-8 semantics (including accepting repeated/circular references) are
unchanged.

Each array element counts as one node, including an element whose value is an
array; the root array and keys do not count as nodes. Only native arrays add
depth: an empty root has depth 1, and an empty nested array still adds one
level. String bytes are counted with `strlen`, not as characters: all string
keys and string values contribute, including nested keys; integer keys and
non-string scalars do not. Objects, their properties and iterables are not
traversed. For `source` mappings, body, query and route budgets are checked
independently, rather than added into one combined total.

Budgets count the array structure that reaches the middleware: element nodes,
nesting depth and the bytes of string keys and values are measured and
bounded. What is *not* bounded is the cost and memory already spent by the body
parser or by application code producing that array, nor the work of
application objects, iterables and custom constructors reached from it. The
package is not a full sandbox for arbitrary parsed input.

The request-scoped input snapshot is shallow: it holds the arrays retrieved
from the request, not deep copies. Custom code must not mutate nested
references in those arrays between mappings in the same request — the package
does not deep-copy the payload or re-validate each DTO.

### Error response options

| Option | Type | Default | Description |
|---|---|---|---|
| `error_response.max_messages` | `?int` | `null` | Maximum mapping messages retained by `DefaultMappingErrorResponder`. `null` keeps all. When the limit is reached, one extra message (`Additional mapping errors were omitted.`) is appended to the root path. It does not stop Valinor from building the full error tree |
| `error_response.max_response_bytes` | `?int` | `null` | Maximum serialized JSON body size in bytes (no headers/compression). Minimum `256`. Oversized bodies are replaced with a fixed envelope. `null` disables the limit |

Both limits apply only to the built-in `DefaultMappingErrorResponder`. Custom
responders keep their own contract and are responsible for their own limits.

`max_response_bytes` caps the serialized JSON body. After all retained messages
are formatted, a conservative check of path and message bytes rejects obviously
oversized details before JSON encoding. Other collections are checked against
the fully encoded body, including escaping and Unicode; an exact fit is accepted.
Retained formatter and UTF-8 errors still propagate.

The cap bounds the transmitted body, not the total memory used by the body
parser, Valinor's error tree or retained message collection. Custom extension
messages and exceptions from user formatters/constructors are not sandboxed or
sanitized by this package.

The package namespace `sirix_mezzio_valinor` accepts only `mapper`,
`input_limits` and `error_response`. Each built-in factory that reads this
namespace rejects unknown section names with `InvalidMapRequestConfiguration`
and the full `sirix_mezzio_valinor.<key>` path. Unrelated application configuration
outside this namespace remains unrestricted. Validation happens when the
relevant factory is invoked; custom services retain their own configuration contract.

### Mapper options

| Option | Type | Default | Description |
|---|---|---|---|
| `cache_dir` | `?string` | `null` | Path to cache directory. When set, Valinor caches compiled type metadata via `FileSystemCache` |
| `cache_watch` | `bool` | `false` | Wrap cache with `FileWatchingCache` to auto-invalidate when PHP files change (use in dev) |
| `configurators` | `array<string\|MapperBuilderConfigurator>` | `[]` | Services or class-strings applied via `configureWith()` |
| `allow_superfluous_keys` | `bool` | `true` | Allow extra keys in input that are not mapped. For HTTP request mapping, extra top-level keys in body/query/route are still ignored; this flag primarily affects direct array mapping through the registered `TreeMapper` |
| `allow_scalar_value_casting` | `bool` | `true` | Allow automatic scalar type casting (e.g. `int` → `string`). For HTTP mapping, strings from query/route parameters are always cast to target scalar types; this flag mainly controls body/array mapping behavior |
| `allow_permissive_types` | `bool` | `false` | Allow `mixed` type to accept any value |
| `allow_undefined_values` | `bool` | `false` | Fill missing keys with `null` instead of failing |
| `support_date_formats` | `list<string>` | `[]` | Additional date formats appended to those already supported by the configured builder. If no configurator replaces them, Valinor's default RFC 3339 / timestamp formats are preserved; otherwise the configurator's list is the base |

The `mapper` section accepts only these eight options. Unknown option names
throw `InvalidMapRequestConfiguration` with the full
`sirix_mezzio_valinor.mapper.<key>` path before cache setup or configurators run.
Absent mapper configuration still creates the default builder. Existing option type checks remain in
place, including requiring a string-keyed mapper map.

`cache_watch` must be a boolean even when `cache_dir` is absent, `null`, empty
or whitespace-only. Invalid values throw `InvalidConfigValueException`.
Without a non-empty cache directory, an absent, `false` or `true` watcher option
still creates no file-system cache.

### Cache

When `cache_dir` is set, Valinor caches compiled reflection data for mapped DTO
types. This package separately keeps only handler and mapping metadata in
memory. Under PHP-FPM that metadata lives for one request; persistent workers
reuse it while their `WeakMap` entries can be released with routes and wrappers.
The WeakMaps follow object lifetimes; strong class/reflection caches and Valinor
type metadata remain for the worker lifetime.
Changing loaded PHP attributes requires a worker restart; `cache_watch` is a
Valinor file-cache watcher, not an attribute watcher.

- **Production**: set `cache_dir` and leave `cache_watch` disabled (default)
- **Development**: set `cache_watch: true` so cache invalidates automatically when PHP files change

To pre-warm the cache during deployment, use the same `MapperBuilder` service
that runtime uses, so the cache keys match exactly:

```php
$mapperBuilder = $container->get(\CuyZ\Valinor\MapperBuilder::class);

$mapperBuilder->warmupCacheFor(
    \App\Domain\CreateUserRequest::class,
    \App\Domain\PaginationRequest::class,
    // ...
);
```

`$container` is the application container already configured with this
package's `ConfigProvider`. Warm up with the same PHP version, configuration,
and installed dependencies that the deployed runtime will use, and update the
cache directory together with each release. When `cache_watch` is disabled
(the production default), the cache is not invalidated automatically when PHP
files change.

This warmup example applies to the mapper built from the registered
`MapperBuilder` service. If your application overrides `TreeMapper::class`
with a custom implementation, that mapper may use a different builder or no
builder at all; the package does not guarantee cache-key compatibility with
arbitrary `TreeMapper` overrides.

### Mapper configurators

Configurators are applied in declaration order. Each `allow_*` flag is additive:
`true` enables the capability, while `false` adds nothing and does not disable
it if a configurator already enabled it. This applies to scalar casting,
superfluous keys, permissive types and undefined values. Date formats from
`support_date_formats` are appended after configurators, preserving order and
removing duplicates.

HTTP mapping has additional Valinor rules: extra top-level body/query/route
keys are ignored, and query/route strings are cast to target scalars regardless
of the corresponding flags. Direct array mapping uses the builder's options.

`mapper.configurators` supports:

- service id (resolved from container)
- class-string implementing `MapperBuilderConfigurator` (instantiated if no service is registered and the class has a public constructor with no required arguments, or no constructor)
- `MapperBuilderConfigurator` instance

Container services take precedence over direct construction. Register configurators
with required constructor arguments in the container. Invalid entries are always
rejected: unknown entries, wrong types, abstract classes, inaccessible
constructors and unregistered classes with required constructor arguments throw
`InvalidMapRequestConfiguration`, naming `mapper.configurators[index]` and the
identifier or type. Array keys are retained for diagnostics.

Optional constructor arguments remain supported for direct construction. A
registered service of the wrong type throws `InvalidContainerServiceException`.
Exceptions from constructors, container resolution or `configureMapperBuilder()`
propagate;
PSR-11 not-found exceptions retain their cause in the container resolver's
contextual `MissingContainerServiceException`.

## Error responders

On a mapping failure, the middleware delegates to
`MappingErrorResponderInterface`. The responder receives a
`MappingErrorContext` whose `error` is either Valinor `MappingError` or this
package's `RequestInputError`, plus the current PSR-7 request, `MapRequest`,
Valinor target signature in the existing `$dtoClass` field, mapping source
(`body`, `query`, `route`, or `source`), and request attribute key.
For `RequestInputError`, inspect `reason` and `inputSource`
instead of calling Valinor's `messages()`.

The package registers `MappingErrorResponderInterface` to the built-in
`DefaultMappingErrorResponder`. Override that service in your container to
change the application-wide response contract:

```php
use Fig\Http\Message\StatusCodeInterface;
use CuyZ\Valinor\Mapper\MappingError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Sirix\Mezzio\Valinor\Error\MappingErrorContext;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;
use Sirix\Mezzio\Valinor\Error\RequestInputError;

final class ProblemDetailsResponder implements MappingErrorResponderInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function respond(MappingErrorContext $context): ResponseInterface
    {
        if ($context->error instanceof RequestInputError) {
            $errors = [[
                'code' => $context->error->reason,
                'detail' => $context->error->getMessage(),
                'source' => $context->error->inputSource,
            ]];
        } else {
            /** @var MappingError $error */
            $error = $context->error;
            $errors = array_map(
                static fn ($message): array => [
                    'code' => $message->code(),
                    'detail' => (string) $message,
                ],
                [...$error->messages()],
            );
        }

        $body = json_encode([
            'type' => 'https://example.test/problems/validation-error',
            'title' => 'Validation failed',
            'status' => StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY,
            'errors' => $errors,
        ], JSON_THROW_ON_ERROR);

        return $this->responseFactory
            ->createResponse(StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY)
            ->withHeader('Content-Type', 'application/problem+json')
            ->withBody($this->streamFactory->createStream($body));
    }
}
```

Register `ProblemDetailsResponder` as the service for
`MappingErrorResponderInterface`. Responder classes need not be stateless: the
container can inject a translator, logger, response factory, or request-id
provider.

For a typical Mezzio application using laminas-servicemanager, register the
concrete responder and alias the package interface to it in your application
configuration:

```php
use App\Error\ProblemDetailsResponder;
use App\Factory\ProblemDetailsResponderFactory;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;

return [
    'dependencies' => [
        'factories' => [
            ProblemDetailsResponder::class => ProblemDetailsResponderFactory::class,
        ],
        'aliases' => [
            MappingErrorResponderInterface::class => ProblemDetailsResponder::class,
        ],
    ],
];
```

`ProblemDetailsResponderFactory` is a conventional invokable factory that
receives the container and creates the responder with its dependencies.
Registering the concrete class is also required when it is referenced by a
per-mapping `errorResponder` attribute.

For a single mapping, use `errorResponder` on `MapRequest` with a fully qualified
class name or a named container service ID. The middleware never constructs the service directly;
an explicit responder must implement `MappingErrorResponderInterface` and be
registered under the requested identifier.

```php
#[MapRequest(body: CreateUserRequest::class, errorResponder: ProblemDetailsResponder::class)]
final class CreateUserHandler implements RequestHandlerInterface
{
    // ...
}
```

Named service IDs also work in `valinor_mappings`. For example, register
`problem.details` using the same responder factory and use that ID on the mapping:

```php
return [
    'dependencies' => [
        'factories' => [
            'problem.details' => ProblemDetailsResponderFactory::class,
        ],
    ],
];

#[MapRequest(body: CreateUserRequest::class, errorResponder: 'problem.details')]
final class CreateUserHandler implements RequestHandlerInterface
{
    // ...
}
```

Resolution is lazy: the explicit service is requested only when that mapping
raises `MappingError` or `RequestInputError`, so successful requests do not
resolve it. A missing service throws `MissingContainerServiceException` with
the requested identifier and factory context; a service of the wrong type
throws `InvalidContainerServiceException`. Service factory exceptions
propagate; PSR-11 not-found failures are contextualized with the original
exception as their cause. These configuration errors escape the middleware
for the application to handle instead of becoming a client `422` response.
Omitting `errorResponder` or setting it to `null` keeps the application-wide
responder and its built-in fallback.

## Default error response and migration

When no custom application-wide responder is configured, the built-in
`DefaultMappingErrorResponder` registered by `ConfigProvider` is used. Its
response contract is fixed:

```json
{
  "error": "Mapping failed",
  "messages": {
    "field": ["...message..."]
  }
}
```

It always returns status `422` and `Content-Type: application/json`, including
for `RequestInputError`. `messages` is always a JSON object: keys are string
paths (the root path is the empty string) and each value is a non-empty array of
strings. Numeric paths are preserved as string JSON property names, and an empty
message collection is encoded as `{}` rather than `[]`. To change the response
contract, register an implementation of `MappingErrorResponderInterface`. See the
[4.0 migration guide](docs/MIGRATION-4.0.md) for strict mapper configuration,
numeric JSON paths and inherited callable discovery. Applications upgrading from
earlier versions should first follow the [3.0 guide](docs/MIGRATION-3.0.md) and,
when upgrading from 1.x, the [2.0 guide](docs/MIGRATION-2.0.md).

## Notes and caveats

- Middleware requires `RouteResult` attribute (it is a no-op when route is not matched yet).
- With `sirix/mezzio-routing-attributes`, middleware can be added per-route automatically via attribute scanning.
- Parsed body values other than `array` or `null` return a `RequestInputError`
  before mapping. Other type or validation failures remain Valinor mapping
  errors.
- When using a custom `output`, ensure downstream code reads the same request key.

## Release checklist

Before tagging a stable release:

```bash
composer validate --strict
composer normalize --dry-run --diff
composer analyse-deps
composer check
```

Developer benchmark commands and their limitations are documented in the
[benchmark guide](https://github.com/sirix777/mezzio-valinor-request-mapper/blob/HEAD/docs/BENCHMARKS.md).
