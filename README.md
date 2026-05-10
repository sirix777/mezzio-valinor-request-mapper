# mezzio-valinor-request-mapper
[![Latest Stable Version](http://poser.pugx.org/sirix/mezzio-valinor-request-mapper/v)](https://packagist.org/packages/sirix/mezzio-valinor-request-mapper) [![Total Downloads](http://poser.pugx.org/sirix/mezzio-valinor-request-mapper/downloads)](https://packagist.org/packages/sirix/mezzio-valinor-request-mapper) [![Latest Unstable Version](http://poser.pugx.org/sirix/mezzio-valinor-request-mapper/v/unstable)](https://packagist.org/packages/sirix/mezzio-valinor-request-mapper) [![License](http://poser.pugx.org/sirix/mezzio-valinor-request-mapper/license)](https://packagist.org/packages/sirix/mezzio-valinor-request-mapper) [![PHP Version Require](http://poser.pugx.org/sirix/mezzio-valinor-request-mapper/require/php)](https://packagist.org/packages/sirix/mezzio-valinor-request-mapper)

Typed request mapping for Mezzio handlers via `cuyz/valinor`.

> **Pre-1.0 package:** Not yet production-ready. Public API and configuration may change with breaking changes before `1.0.0`.

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
- JSON error responses on mapping failures
- Optional Valinor error message remapping (`message_map`)

## Requirements

- PHP `~8.2 || ~8.3 || ~8.4 || ~8.5`
- `cuyz/valinor ^2.4`
- `mezzio/mezzio ^3.2`

## Installation

```bash
composer require sirix/mezzio-valinor-request-mapper
```

The package auto-registers its config provider via Composer extra config. If your app does not use `laminas-config-aggregator`, add manually:

```php
\Sirix\Mezzio\Valinor\ConfigProvider::class,
```

## Middleware registration modes

### 1) Standalone Mezzio (without `sirix/mezzio-routing-attributes`)

Register middleware globally after route matching and before dispatch:

```php
$app->pipe(\Mezzio\Router\Middleware\RouteMiddleware::class);
$app->pipe(\Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware::class);
$app->pipe(\Mezzio\Router\Middleware\DispatchMiddleware::class);
```

### 2) With `sirix/mezzio-routing-attributes`

If your app uses `sirix/mezzio-routing-attributes` and it scans/collects route attribute modifiers,
`MapRequest` is discovered as a `RouteAttributeModifierInterface` implementation and
`ValinorRequestMapperMiddleware` is attached to matching routes automatically.

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
new MapRequest(
    body: ?string,   // class-string DTO from parsed body
    query: ?string,  // class-string DTO from query params
    route: ?string,  // class-string DTO from route params
    source: ?string, // class-string DTO from combined request sources
    output: ?string, // request attribute key, defaults to DTO FQCN
    methods: array,  // HTTP methods filter
);
```

Rules:

- `source` is mutually exclusive with `body/query/route`
- if `output` is omitted, mapped DTO is stored under its class name
- `methods = []` means any HTTP method
- `methods` are normalized (`post`, `Post` -> `POST`)
- if multiple `#[MapRequest]` attributes match current method, all of them are applied in declaration order

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
        'error' => [
            'status_code' => 422,
            'key_case' => null, // null|'snake_case'
            'message_map' => [
                // 'Value {source_value} is not a valid string.' => 'This field is required.',
            ],
        ],
    ],
];
```

### Mapper options

| Option | Type | Default | Description |
|---|---|---|---|
| `cache_dir` | `?string` | `null` | Path to cache directory. When set, Valinor caches compiled type metadata via `FileSystemCache` |
| `cache_watch` | `bool` | `false` | Wrap cache with `FileWatchingCache` to auto-invalidate when PHP files change (use in dev) |
| `configurators` | `array<string\|MapperBuilderConfigurator>` | `[]` | Services or class-strings applied via `configureWith()` |
| `allow_superfluous_keys` | `bool` | `true` | Allow extra keys in input that are not mapped |
| `allow_scalar_value_casting` | `bool` | `true` | Allow automatic scalar type casting (e.g. `int` → `string`) |
| `allow_permissive_types` | `bool` | `false` | Allow `mixed` type to accept any value |
| `allow_undefined_values` | `bool` | `false` | Fill missing keys with `null` instead of failing |
| `support_date_formats` | `list<string>` | `[]` | Additional date formats for `DateTimeInterface` mapping |

### Cache

When `cache_dir` is set, Valinor caches compiled reflection data for mapped DTO types, significantly reducing first-request latency.

- **Production**: set `cache_dir` and leave `cache_watch` disabled (default)
- **Development**: set `cache_watch: true` so cache invalidates automatically when PHP files change

To pre-warm the cache during deployment, use a CLI script:

```php
$mapperBuilder = (new \CuyZ\Valinor\MapperBuilder())
    ->withCache(new \CuyZ\Valinor\Cache\FileSystemCache('path/to/cache-dir'));

$mapperBuilder->warmupCacheFor(
    \App\Domain\CreateUserRequest::class,
    \App\Domain\PaginationRequest::class,
    // ...
);
```

### Mapper configurators

`mapper.configurators` supports:

- service id (resolved from container)
- class-string implementing `MapperBuilderConfigurator` (instantiated if service not found)

### Error options

| Option | Type | Default | Description |
|---|---|---|---|
| `status_code` | `int` | `422` | HTTP status code for mapping error responses |
| `key_case` | `null\|'snake_case'` | `null` | Transform error path keys to snake_case |
| `message_map` | `array<string, string>` | `[]` | Remap Valinor error messages by pattern match |

## Error response format

On mapping failure middleware returns JSON:

```json
{
  "error": "Mapping failed",
  "messages": {
    "field": ["...message..."]
  }
}
```

Status code defaults to `422` and can be overridden by `sirix_mezzio_valinor.error.status_code`.

## Notes and caveats

- Middleware requires `RouteResult` attribute (it is a no-op when route is not matched yet).
- With `sirix/mezzio-routing-attributes`, middleware can be added per-route automatically via attribute scanning.
- For body mapping, malformed body structures can still fail at Valinor level and return configured mapping error response.
- When using a custom `output`, ensure downstream code reads the same request key.