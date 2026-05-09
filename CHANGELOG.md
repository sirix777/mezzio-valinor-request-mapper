# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
