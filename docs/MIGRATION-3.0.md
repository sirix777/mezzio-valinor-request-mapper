# Migrating to 3.0

Version 3.0 tightens the HTTP-input contract and requires Valinor 2.4 or newer.
This document covers the user-visible breaking changes. Additional 3.0 changes
(for example the new `MapperBuilder` service and the unified `RequestInputError`
type) are described in the sections below.

## HTTP input must be valid UTF-8

Selected HTTP sources (`body`, `query`, `route`) are now validated for correct
UTF-8 before they are passed to Valinor. This applies to string keys and string
values inside the arrays that the middleware reads for the active mapping
sources.

```php
// Previously a binary string could reach Valinor and sometimes be accepted,
// depending on the target type and formatter.
$query = ['page' => "\xB1\x31"];

// In 3.0 this request returns 422 with reason "invalid_utf8" and the fixed
// message "Request input contains invalid UTF-8."
```

If your application intentionally accepts binary data, pass it through
uploaded files or a custom middleware that runs before this package and places
the decoded value into the request in a form that is not read as a mapped
body/query/route string.

Binary data in sources that are **not** selected for the active mapping (for
example an unrelated `body` when only `query` is mapped) is still ignored.

## Minimum Valinor version

The package now requires `cuyz/valinor: ^2.4` because it relies on the HTTP
mapping API introduced in that release.
