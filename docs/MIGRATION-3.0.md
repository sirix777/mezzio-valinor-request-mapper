# Migrating to 3.0

3.0 makes request mapping configuration and HTTP input handling explicit. It
does not provide a 2.x compatibility mode. If upgrading from 1.x, first apply
the [2.0 migration guide](MIGRATION-2.0.md), then make the changes below.

## 1. Require Valinor 2.4 or newer

The package uses Valinor's HTTP mapping API, which is not present in older 2.x
releases.

Before:

```json
"cuyz/valinor": "^2.0"
```

After:

```json
"cuyz/valinor": "^2.4"
```

## 2. Obtain middleware from the container

The middleware now has four required dependencies: `TreeMapper`,
`MappingErrorResponderResolver`, `MappingPlanResolver`, and
`HttpRequestSourceFactory`. Applications using `ConfigProvider` receive this
graph automatically.

Before, an application could create the old middleware directly with a mapper
and responder-related arguments. After, use the registered service:

```php
use Sirix\Mezzio\Valinor\ConfigProvider;
use Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware;

// Add ConfigProvider::class to the application ConfigAggregator.
$middleware = $container->get(ValinorRequestMapperMiddleware::class);
```

A custom integration that constructs the middleware itself must also construct
and pass `MappingPlanResolver` and `HttpRequestSourceFactory`; a two-argument
constructor call is no longer valid.

If the application uses the routing-attribute scanner, update
`sirix/mezzio-routing-attributes` to `^1.4` (currently 1.4.2 is supported):

```json
"sirix/mezzio-routing-attributes": "^1.4"
```

Versions before 1.4.0 can lose class-level and repeatable `MapRequest`
mappings and register the mapper middleware more than once. The package
intentionally declares a Composer conflict for versions below 1.4.0.

## 3. Register and reuse the configured MapperBuilder

`ValinorTreeMapperFactory` now resolves `MapperBuilder` from the container.
Register the package `ConfigProvider`, or register a compatible
`MapperBuilder::class` service before using that factory.

Before, cache warmup often used an unrelated builder:

```php
(new \CuyZ\Valinor\MapperBuilder())->warmupCacheFor(App\Input\CreateUserRequest::class);
```

After, warm up through the application's configured service so runtime and
warmup use identical cache keys:

```php
$container->get(\CuyZ\Valinor\MapperBuilder::class)->warmupCacheFor(
    App\Input\CreateUserRequest::class,
);
```

The container must be built with the same configuration, PHP version, and
installed dependencies as the deployed runtime. A custom `TreeMapper` override
may use a different builder; its cache compatibility is the application's
responsibility.

## 4. Make MapRequest definitions valid and explicit

Every `MapRequest` must select at least one source. `source` is exclusive with
`body`, `query`, and `route`; source, output, and responder strings cannot be
empty or padded with whitespace. Non-empty `methods` must be a list of non-empty
HTTP tokens. `methods: []` remains the only way to express all methods.

Before:

```php
#[MapRequest]
#[MapRequest(body: ' App\\Input\\CreateUserRequest ', methods: [''])]
final class CreateUserHandler {}
```

After:

```php
#[MapRequest(body: App\Input\CreateUserRequest::class, methods: ['POST'])]
final class CreateUserHandler {}
```

The same validation applies to non-empty `valinor_mappings` route options.
Invalid configuration throws `InvalidMapRequestConfiguration` and is not turned
into a 422 response.

## 5. Give concurrent mappings unique output keys

3.0 rejects two active mapping operations with the same effective request
attribute key before the mapper is called. It no longer permits a later mapping
to overwrite an earlier DTO.

Before:

```php
#[MapRequest(query: App\Input\Filter::class)]
#[MapRequest(body: App\Input\Filter::class)]
final class SearchHandler {}
```

After:

```php
#[MapRequest(query: App\Input\Filter::class, output: 'queryFilter')]
#[MapRequest(body: App\Input\Filter::class, output: 'bodyFilter')]
final class SearchHandler {}
```

Update downstream code to read the chosen output keys. There is no package
option for intentional overwriting.

## 6. Return associative parsed bodies

For `body` and `source` mappings, `ServerRequestInterface::getParsedBody()`
must return `array` or `null`. `null` is passed to Valinor as an empty array;
objects are rejected with `RequestInputError` reason
`unsupported_parsed_body`. The package does not decode JSON itself.

Before, a body parser that returned objects could appear to work in some modes:

```php
$request = $request->withParsedBody(json_decode($json));
```

After, configure it to return associative arrays:

```php
$request = $request->withParsedBody(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
```

Place the application's body parser before the mapper middleware. A `query` or
`route` operation does not read or validate an otherwise unused parsed body.

## 7. Handle RequestInputError in custom responders

`MappingErrorContext::$error` is now
`MappingError|RequestInputError`. Calling `messages()` unconditionally is no
longer safe.

Before:

```php
foreach ($context->error->messages() as $message) {
    // Format Valinor messages.
}
```

After:

```php
use Sirix\Mezzio\Valinor\Error\RequestInputError;

if ($context->error instanceof RequestInputError) {
    $reason = $context->error->reason;
    $source = $context->error->inputSource;
    $message = $context->error->getMessage();
} else {
    foreach ($context->error->messages() as $message) {
        // Format Valinor messages.
    }
}
```

The built-in responder keeps the existing 422 JSON envelope for both error
types. Configuration exceptions and arbitrary application exceptions are not
422 mapping responses.

## 8. Add explicit metadata for aliases and route pipelines

The package only reflects the declared callable handler. It does not instantiate
an alias or inspect a pipeline's internal queue to find a later handler.

Before:

```php
$app->post('/orders', 'order.handler.alias');
```

After:

```php
$route = $app->post('/orders', 'order.handler.alias');
$route->setOptions([
    'valinor_mappings' => [[
        'body' => App\Input\CreateOrderRequest::class,
        'methods' => ['POST'],
    ]],
]);
```

An empty `valinor_mappings` list (or no key) still falls back to reflection;
a non-empty list is the explicit override. Applications using the routing
attribute scanner can use its generated route metadata instead.

## 9. Put attributes on the method dispatch calls

Method-level attributes are read only from the method actually selected by the
PSR-15 handler: `process()` for middleware, `handle()` for request handlers,
or `__invoke()` for invokable objects. Attributes on unrelated public methods
are ignored.

Before:

```php
final class OrdersHandler implements \Psr\Http\Server\RequestHandlerInterface
{
    #[MapRequest(query: App\Input\Filter::class)]
    public function list(): void {}

    public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {}
}
```

After:

```php
final class OrdersHandler implements \Psr\Http\Server\RequestHandlerInterface
{
    #[MapRequest(query: App\Input\Filter::class)]
    public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {}
}
```

Class-level attributes still apply before attributes on the selected method.

## 10. Recheck configured date formats

Additional `support_date_formats` now extend the builder's current formats
after configurators run; duplicate values are removed without changing order.
This preserves Valinor defaults unless a configurator replaces them.

Before, applications could assume the option replaced the complete list:

```php
$mapper = [
    'support_date_formats' => ['d/m/Y'],
];
```

After, keep only formats that should be added:

```php
$mapper = [
    'support_date_formats' => ['d/m/Y'], // adds to configured builder formats
];
```

Use a custom `MapperBuilderConfigurator` when the application must replace the
standard formats rather than supplement them.

## 11. Keep binary data out of mapped HTTP strings

Selected `body`, `query`, and `route` sources now require valid UTF-8 in all
string keys and nested string values. The package rejects invalid bytes before
they reach Valinor with `RequestInputError` reason `invalid_utf8` and its fixed
safe message.

Before:

```php
$request = $request->withQueryParams(['page' => "\xB1\x31"]);
```

After, use a valid textual value for a mapped field, or pass binary data through
an uploaded file or application-specific flow that is not mapped as body, query,
or route input:

```php
$request = $request->withQueryParams(['page' => '1']);
```

Sources not selected for the active mapping remain unread. See the local
[Valinor invalid-UTF-8 reproduction](upstream/valinor-invalid-utf8.md) for the
upstream formatter failure that this input guard prevents.
