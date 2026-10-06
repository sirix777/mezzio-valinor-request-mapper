# Migrating to 4.0

4.0 makes mapper configuration always strict and includes the numeric JSON
path and inherited callable discovery corrections below. When upgrading from
an older release, apply the [3.0 migration guide](MIGRATION-3.0.md) first; for
1.x, start with the [2.0 guide](MIGRATION-2.0.md).

## 1. Remove the strict flag and validate mapper options

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

## 3. Read numeric error paths as JSON object properties

The default responder's `messages` is always a JSON object. Numeric paths
previously could produce an array such as `[["..."]]`; they now produce
`{"0":["..."]}`. An empty collection is `{}` instead of `[]`. Update clients
and response snapshots to read path properties, including numeric strings.
The status `422`, JSON content type and `error` field remain unchanged.

## 4. Check inherited handler callables

Inherited first-class instance and static method callables now use class
attributes from the called child class, matching equivalent array callables.
Attributes on the inherited method remain active; parent class attributes are
not inherited automatically. Check routes using child-class method callables
and update their expected mappings or declare the intended child attributes.
