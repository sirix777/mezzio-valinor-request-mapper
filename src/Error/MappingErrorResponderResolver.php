<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Error;

use Psr\Container\ContainerExceptionInterface;
use Sirix\ContainerResolver\ContainerResolver;
use Sirix\ContainerResolver\Exception\InvalidContainerServiceException;
use Sirix\ContainerResolver\Exception\MissingContainerServiceException;

final readonly class MappingErrorResponderResolver
{
    public function __construct(private MappingErrorResponderInterface $defaultResponder, private ContainerResolver $containerResolver) {}

    /**
     * @param null|class-string<MappingErrorResponderInterface> $responderClass
     *
     * @throws ContainerExceptionInterface
     * @throws MissingContainerServiceException when the explicit responder is not registered
     * @throws InvalidContainerServiceException when the explicit responder has an invalid type
     */
    public function resolve(?string $responderClass): MappingErrorResponderInterface
    {
        if (null === $responderClass) {
            return $this->defaultResponder;
        }

        return $this->containerResolver->getAs($responderClass, MappingErrorResponderInterface::class);
    }
}
