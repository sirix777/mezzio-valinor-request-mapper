<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Error;

use CuyZ\Valinor\Mapper\MappingError;
use Psr\Http\Message\ServerRequestInterface;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;

final readonly class MappingErrorContext
{
    /**
     * @param string                          $dtoClass Non-empty Valinor target type signature
     * @param 'body'|'query'|'route'|'source' $source
     */
    public function __construct(
        public MappingError|RequestInputError $error,
        public ServerRequestInterface $request,
        public MapRequest $mapRequest,
        public string $dtoClass,
        public string $source,
        public string $requestAttributeKey,
    ) {}
}
