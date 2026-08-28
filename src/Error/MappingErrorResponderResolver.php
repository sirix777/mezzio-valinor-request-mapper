<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Error;

use Psr\Container\ContainerExceptionInterface;
use Sirix\ContainerResolver\ContainerResolver;

final readonly class MappingErrorResponderResolver
{
    public function __construct(private MappingErrorResponderInterface $defaultResponder, private ContainerResolver $containerResolver) {}

    /**
     * @param null|class-string<MappingErrorResponderInterface> $responderClass
     *
     * @throws ContainerExceptionInterface
     */
    public function resolve(?string $responderClass): MappingErrorResponderInterface
    {
        if (null === $responderClass) {
            return $this->defaultResponder;
        }

        return $this->containerResolver->optionalAs($responderClass, MappingErrorResponderInterface::class)
            ?? $this->defaultResponder;
    }
}
