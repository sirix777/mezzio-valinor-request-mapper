# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Persistent-worker soak CLI with static and ephemeral route modes, changing
  request IDs, object-release probes and a last-five-checkpoint used-memory gate;
  separate PHP heap, allocator peak and RSS evidence is recorded where available.
- Optional `input_limits` budgets (`max_nodes`, `max_depth`,
  `max_total_string_bytes`), all defaulting to `null`. When enabled, selected
  body/query/route inputs are rejected before mapping with stable
  `RequestInputError` reasons (`input_node_limit_exceeded`,
  `input_depth_limit_exceeded`, `input_string_bytes_limit_exceeded`,
  `cyclic_input`). Limits are checked per source in one iterative traversal
  together with UTF-8 validation; with all limits disabled the previous
  reference handling is preserved.
- `error_response.max_messages` and `error_response.max_response_bytes` options
  for the built-in `DefaultMappingErrorResponder`; both default to `null`
  (unlimited) and apply only to the default responder. An oversized serialized
  body is replaced by a fixed `422` envelope with a root message; the initial
  encoding and custom extension messages are not sandboxed.

### Changed

- Breaking: mapper configuration is always strict. Unknown string keys in
  `sirix_mezzio_valinor.mapper` throw `InvalidMapRequestConfiguration` with their
  full path before cache setup or configurators run. Invalid, unresolved and
  unconstructible configurators always throw with their array key and
  identifier/type. Register configurators with required constructor arguments
  in the container; optional arguments remain supported for direct construction.
  Container precedence, exception propagation, additive flags and date formats
  are preserved, as are existing configuration type checks.

### Removed

- `mapper.strict_configurators`; providing it now throws
  `InvalidMapRequestConfiguration`, regardless of its value. See the
  [4.0 migration guide](docs/MIGRATION-4.0.md).

### Fixed

- Inherited first-class handler callables now discover attributes from the
  called child class, matching equivalent array callables. Attributes on the
  inherited method remain active; parent class attributes are not inherited.
  This correction can change which mappings run for existing routes.
- Documented additive mapper flags: `false` does not undo capabilities enabled
  by configurators. Configurators retain declaration order, followed by flags
  and appended date formats; HTTP-specific extra-key/casting rules are retained.
- Default error response `messages` is now always a JSON object. Numeric mapping
  paths (for example a root `list<int>` with failing elements) previously
  serialized as a JSON array; they are now string JSON property names, and an
  empty collection is `{}` instead of `[]`.

### Release classification

- These changes require a major release under the project's Semantic
  Versioning policy because strict mapper configuration, numeric JSON paths and
  inherited callable discovery change observable behavior. Additive limits
  remain disabled by default and could be released separately.

## [3.0.0] - 2026-09-12

### Added

- `MapRequest` implements `AggregatingRouteAttributeModifierInterface`, which retains class- and method-level mappings and adds `ValinorRequestMapperMiddleware` once per route.
- Configured `MapperBuilder` service, shared by runtime mapping and cache warmup.
- `RequestInputError` for invalid request input, delivered to existing responders
  through `MappingErrorContext` alongside Valinor `MappingError`.
- Explicit `valinor_mappings` route metadata for aliases and route pipelines.
- Metadata caches for resolved handler targets and mapping definitions; request
  data and DTOs are not cached.

### Changed

- The optional routing-attributes integration requires `sirix/mezzio-routing-contracts ^1.2` and rejects `sirix/mezzio-routing-attributes <1.4.0` when both packages are installed.
- `ConfigProvider` registers the configured `MapperBuilder`, mapping-plan, and
  HTTP-input services needed by the middleware.
- Direct middleware construction requires mapping-plan and HTTP-input
  dependencies in addition to the mapper and responder resolver.
- Request-handler discovery uses the method actually dispatched (`process`,
  `handle`, or `__invoke`); class attributes precede attributes on that method.
- `MapRequest` attributes and non-empty `valinor_mappings` are validated
  strictly; invalid configuration throws `InvalidMapRequestConfiguration`.
- Concurrent active mappings must use unique effective request-attribute keys.
- `body` and `source` accept only array or null parsed bodies; unsupported
  bodies and invalid UTF-8 in selected body/query/route strings return the
  fixed safe 422 input-error response.
- HTTP mapping retains Valinor HTTP semantics: top-level extra HTTP fields are
  ignored even when `allow_superfluous_keys` is false, and query/route strings
  are cast to target scalars even when scalar casting is disabled for arrays.
- Configured date formats extend the builder's current formats after
  configurators, preserving order while removing duplicates.
- Cache warmup must use the registered `MapperBuilder`; Valinor file-cache
  watching does not reload loaded handler attributes in persistent workers.

### Fixed

- Lower bound of `cuyz/valinor` raised from `^2.0` to `^2.4`; previous constraint allowed versions that do not provide the `CuyZ\Valinor\Mapper\Http\HttpRequest` API and `MapperBuilderConfigurator` used by this package.

### Removed

- Implicit attribute discovery for aliases and arbitrary handlers inside route
  pipelines; provide explicit route metadata instead.
- Last-write-wins behavior for colliding mapping output keys.

## [2.0.0] - 2026-08-28

### Added

- `MappingErrorResponderInterface` and immutable `MappingErrorContext` for application-defined mapping error responses.
- `DefaultMappingErrorResponder`, used as a safe fallback when no application-wide responder is registered.
- Per-mapping `errorResponder` support on `#[MapRequest]`; responder services are resolved exclusively from the container.
- Mapping error context fields for the Valinor error, request, mapping attribute, DTO class, source, and request attribute key.
- Direct `sirix/container-resolver ^1.0` dependency for strict factory service resolution and configuration reading.

### Changed

- `ValinorRequestMapperMiddleware` delegates mapping errors to the selected responder instead of creating JSON responses directly.
- `ConfigProvider` registers the `TreeMapper`, `DefaultMappingErrorResponder`, `MappingErrorResponderResolver`, and `ValinorRequestMapperMiddleware` services.
- The default responder has a fixed `422`, `application/json`, `{"error":"Mapping failed","messages":...}` contract.
- Factories reject services registered under incompatible types.
- The package now uses PSR-17 response and stream factories instead of requiring Diactoros, Stratigility, or the Mezzio framework implementation at runtime.

### Removed

- Legacy `sirix_mezzio_valinor.error.status_code`, `key_case`, and `message_map` configuration.
- Legacy `ValinorRequestMapperMiddleware` constructor signature: `(TreeMapper $mapper, array $errorConfig = [], MessageFormatter ...$messageFormatters)`.
- `ValinorTreeMapperFactory` constructor injection and optional `__invoke()` container argument; its factory now receives the PSR-11 container directly.
- Runtime requirements on `laminas/laminas-diactoros`, `laminas/laminas-stratigility`, and `mezzio/mezzio`; they remain development dependencies for tests.

See the [2.0 migration guide](docs/MIGRATION-2.0.md) for the new middleware constructor and responder configuration.

## [1.0.0] - 2026-06-02

### Added

- Stable `#[MapRequest]` public attribute API for class-level and method-level request DTO mapping.
- Stable `ValinorRequestMapperMiddleware` behavior for body, query, route, and combined `source` mapping.
- Full method-level reflection support for PSR-15 `process()`, request handler `handle()` wrappers, and invokable route middleware.
- `TreeMapper` service factory registration, allowing applications to override or reuse the configured Valinor mapper.
- Direct dependency declarations for `laminas/laminas-stratigility`, `psr/http-message`, and `psr/http-server-handler`.

### Changed

- Require stable `sirix/mezzio-routing-contracts ^1.0`.
- Document stable release requirements and release checklist.

## [0.1.1] - 2026-05-10

### Fixed

- Read `valinor_mappings` directly from route options instead of nested `defaults` key

## [0.1.0] - 2026-05-09

### Added

- `#[MapRequest]` attribute for class and method targets (repeatable)
- Mapping from parsed body (`body`), query params (`query`), route params (`route`), and combined HTTP request (`source`)
- Support for Valinor HTTP attributes (`FromBody`, `FromQuery`, `FromRoute`) with `source` mapping
- Optional request attribute key override via `output`
- HTTP method filter via `methods` (case-insensitive, normalized to uppercase)
- JSON error responses on mapping failures (defaults to `422`)
- Optional Valinor error message remapping via `message_map`
- Configurable mapper settings: `configurators`, `allow_superfluous_keys`, `allow_scalar_value_casting`
- Snake-case key conversion for error paths (`key_case`)
- `ConfigProvider` with factory for `ValinorRequestMapperMiddleware`
- Composer extra config for automatic Laminas/Mezzio config provider registration
- Integration with `sirix/mezzio-routing-attributes` for automatic per-route middleware registration
