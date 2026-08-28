# Migrating to 2.0

Version 2.0 removes the legacy mapping-error configuration and changes the
`ValinorRequestMapperMiddleware` constructor. Configure error handling through
`MappingErrorResponderInterface` instead.

## Middleware constructor

```php
// 1.x
new ValinorRequestMapperMiddleware(
    $mapper,
    $errorConfig,
    ...$formatters,
);

// 2.x
new ValinorRequestMapperMiddleware(
    $mapper,
    $errorResponderResolver,
);
```

Register the middleware through the package `ConfigProvider` instead of
constructing it yourself.

## Error configuration

Remove `sirix_mezzio_valinor.error`. The package no longer reads
`status_code`, `key_case`, or `message_map`.

```php
// 1.x
'sirix_mezzio_valinor' => [
    'error' => [
        'status_code' => 400,
        'message_map' => [...],
    ],
],

// 2.x
'dependencies' => [
    'aliases' => [
        MappingErrorResponderInterface::class => AppMappingErrorResponder::class,
    ],
],
```

`AppMappingErrorResponder` must implement `MappingErrorResponderInterface`.
When no custom application-wide responder is registered, the built-in
`DefaultMappingErrorResponder` registered by `ConfigProvider` is used. It
always returns status `422` and a JSON body with `error` and `messages` keys.

For one mapping only, set `errorResponder` on `#[MapRequest]` and register that
responder class in the container. If it is not registered, the package falls
back to the application-wide responder.
