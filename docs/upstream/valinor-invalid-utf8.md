# Upstream reproduction: invalid UTF-8 in HTTP query value with intl

## Environment

The reproduction below was run in a `php:8.2-cli` container with `ext-intl`
installed on the fly against the host project's vendor:

- PHP: 8.2.33
- `cuyz/valinor`: 2.6.0
- `ext-intl`: enabled, ICU 76.1 (`INTL_ICU_VERSION`)
- `ext-mbstring`: optional, not required for the reproduction

## Reproduction

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use CuyZ\Valinor\Mapper\Http\HttpRequest;
use CuyZ\Valinor\MapperBuilder;
use Laminas\Diactoros\ServerRequest;

final readonly class PaginationRequest
{
    public function __construct(public int $page) {}
}

$request = (new ServerRequest())->withQueryParams([
    'page' => "\xB1\x31",
]);

$httpRequest = HttpRequest::fromPsr($request);

try {
    $mapper = (new MapperBuilder())->mapper();
    $dto    = $mapper->map(PaginationRequest::class, $httpRequest);

    echo "Mapped unexpectedly: ";
    var_dump($dto);
} catch (\CuyZ\Valinor\Mapper\MappingError $e) {
    echo "Expected MappingError: " . $e->getMessage() . PHP_EOL;
} catch (\CuyZ\Valinor\Utility\String\StringFormatterError $e) {
    echo "Actual StringFormatterError: " . $e->getMessage() . PHP_EOL;
}
```

## Actual result (with intl)

```
Actual StringFormatterError: Message formatter error using `Value {source_value} is not a valid integer.`: Invalid UTF-8 data in string argument: ''�1'': U_INVALID_CHAR_FOUND.
```

The formatter fails while building the human-readable message for the invalid
integer value, because the raw bytes `\xB1\x31` are not valid UTF-8.

## Expected result

A `CuyZ\Valinor\Mapper\MappingError` describing the type failure at path `page`,
without exposing the invalid bytes in a formatter exception.

## Impact on this package

`mezzio-valinor-request-mapper` prevents the formatter from receiving invalid
UTF-8 by validating selected HTTP sources (body/query/route) before passing them
to Valinor. The package returns its own `RequestInputError` with reason
`invalid_utf8` and a fixed safe message, so the upstream formatter exception is
not reached for HTTP input.

## Notes

- Without `ext-intl` the reproduction may instead produce a `MappingError`,
  because Valinor falls back to a non-intl formatter that tolerates the bytes.
- This is a local reproduction kept for reference; it is not an upstream issue
  and no upstream issue is created automatically by this package.
