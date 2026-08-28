# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
