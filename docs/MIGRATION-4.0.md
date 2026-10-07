# Migrating to 4.0

4.0 makes mapper configuration always strict, requires explicitly configured
error responders, rejects intrinsic output-key collisions during construction,
lets empty route metadata disable mapping, and includes the
numeric JSON path and inherited callable discovery corrections below. When
upgrading from an older release, apply the
[3.0 migration guide](MIGRATION-3.0.md) first; for
1.x, start with the [2.0 guide](MIGRATION-2.0.md).

## 1. Validate package sections and mapper options

Keep only `mapper`, `input_limits` and `error_response` under
`sirix_mezzio_valinor`. Correct section-name typos and move unrelated application
settings outside that namespace. Each built-in factory that reads the namespace
rejects unknown sections with `InvalidMapRequestConfiguration` and the full
`sirix_mezzio_valinor.<key>` path when invoked. Custom services keep their own
configuration contract; no eager startup validation is added.
Numeric namespace keys, `null` and scalar namespace values retain the existing
`ConfigReader` type failures and originating factory context.

Remove `sirix_mezzio_valinor.mapper.strict_configurators` from application
configuration. The key is rejected even when its value is `false` or `null`.
There is no permissive mode.

The mapper section accepts only `configurators`, `allow_superfluous_keys`,
`allow_scalar_value_casting`, `allow_permissive_types`, `allow_undefined_values`,
`support_date_formats`, `cache_dir` and `cache_watch`. Correct misspellings and
remove unrelated keys from that section. Unknown string keys throw
`InvalidMapRequestConfiguration` with the full
`sirix_mezzio_valinor.mapper.<key>` path before cache setup or configurators run.
Existing type validation remains in place: numeric mapper keys still fail the
`ConfigReader` string-keyed map check with `InvalidConfigValueException`.
Absent mapper configuration continues to create the default builder.

Use a boolean for `mapper.cache_watch`, even when `cache_dir` is absent, `null`,
empty or whitespace-only. Values such as `null`, `'true'`, `1` and `[]` now throw
`InvalidConfigValueException` without a cache directory too. An absent, `false`
or `true` watcher option without a non-empty directory still creates no
file-system cache.

## 2. Register configurators that need constructor arguments

Every configurator must be a `MapperBuilderConfigurator` instance, a registered
service of that type, or a concrete class implementing that interface that
can be instantiated without required arguments. Direct construction requires
a public constructor (or no constructor); optional arguments are supported.
Register classes with required arguments in the container, under their class
name or a service alias, and use that identifier in `mapper.configurators`.

Unknown identifiers, unsuitable values, abstract classes, inaccessible
constructors and unregistered classes requiring arguments now throw
`InvalidMapRequestConfiguration`, naming `mapper.configurators[index]` and
the identifier or type. Remove stale entries that were previously skipped.
Container services still take precedence; a service of the wrong type throws
`InvalidContainerServiceException`. Constructor, container and configurator
exceptions propagate. PSR-11 not-found failures are contextualized by the
container resolver as `MissingContainerServiceException` with the original
exception as their cause.

Configurators retain declaration order. The `allow_*` flags remain additive:
`false` does not undo a capability enabled by a configurator. Configured date
formats still append to the builder's existing formats, removing duplicates
while preserving order.

## 3. Register explicitly configured error responders

Register every per-mapping `errorResponder` identifier in the container with a
service implementing `MappingErrorResponderInterface`. An existing class is
not enough: the middleware does not instantiate responder classes.

Missing explicit services now throw `MissingContainerServiceException` with
the requested identifier and factory context instead of falling back to the
default responder. Services of the wrong type throw
`InvalidContainerServiceException`; service factory exceptions propagate.
PSR-11 not-found failures preserve the original exception as their cause.
The application must handle these configuration errors: the middleware does
not convert them to a client `422` response.

Resolution remains lazy, only when the affected mapping raises `MappingError`
or `RequestInputError`. Successful requests do not resolve the explicit
responder. Omit `errorResponder` or set it to `null` to keep the application-wide
responder and its existing built-in fallback.

## 4. Remove empty mapping metadata to keep discovery

An explicit `valinor_mappings: []` in final route options now deliberately
disables request mapping. Previously it fell back to handler attributes. Remove
the `valinor_mappings` key if you want reflection discovery to continue.

When disabled, this middleware does not instantiate handler attributes, read
or validate input, or call the mapper. It passes the original request downstream;
other application middleware continues to run. A non-empty list still replaces
reflection mappings. Invalid values, including `null`, strings, associative
arrays instead of a list and invalid definitions, still throw
`InvalidMapRequestConfiguration`.

With routing-attributes, the scanner appends its mappings. Set the empty
override in final route options after scanner assembly; an initial `[]` does
not prevent the scanner from adding mappings.

## 5. Read numeric error paths as JSON object properties

The default responder's `messages` is always a JSON object. Numeric paths
previously could produce an array such as `[["..."]]`; they now produce
`{"0":["..."]}`. An empty collection is `{}` instead of `[]`. Update clients
and response snapshots to read path properties, including numeric strings.
The status `422`, JSON content type and `error` field remain unchanged.

## 6. Check inherited handler callables

Inherited first-class instance and static method callables now use class
attributes from the called child class, matching equivalent array callables.
Attributes on the inherited method remain active; parent class attributes are
not inherited automatically. Check routes using child-class method callables
and update their expected mappings or declare the intended child attributes.

## 7. Split definitions with colliding output keys

An individual `MapRequest` now throws `InvalidMapRequestConfiguration` during
construction when multiple sources resolve to the same output key. This also
applies while parsing each `valinor_mappings` item and before method filtering.
For example, `MapRequest(body: BodyDto::class, query: QueryDto::class,
output: 'payload')` is invalid even with `methods: ['PATCH']`; so is mapping
the same target from both body and query without an explicit output.
The exception names the repeated key and the first two conflicting sources.

Use separate definitions with distinct output keys:

```php
#[MapRequest(body: BodyDto::class, output: 'bodyPayload')]
#[MapRequest(query: QueryDto::class, output: 'queryPayload')]
final class Handler implements RequestHandlerInterface
{
    // ...
}
```

For route options, split the item into two `valinor_mappings` items with the
same distinct outputs. Multiple sources with different targets and no explicit
output remain valid. Separate definitions may still reuse an output for
non-overlapping HTTP methods: collisions across definitions are checked only
after method filtering.

Targets continue to accept non-empty Valinor type signatures, including DTO
FQCNs, generic DTOs and array shapes. Type grammar is interpreted by Valinor
during mapping. The default output key is the exact target string; for
`query: 'array{page: int}'`, use `output: 'payload'` to read the resulting array
with `$request->getAttribute('payload')`. The existing `MappingOperation` and
`MappingErrorContext` `$dtoClass` fields carry the target signature and retain
their names.
