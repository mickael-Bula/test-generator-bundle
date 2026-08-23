<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Dto;

/**
 * Représente le résultat attendu d'une méthode de test (valeur de retour ou exception levée).
 */
readonly class ExpectedBehaviorDto
{
    public function __construct(
        private mixed $returnValue = null,
        private ?string $throwsException = null,
        private ?string $exceptionMessage = null,
    ) {
    }

    public function getReturnValue(): mixed
    {
        return $this->returnValue;
    }

    public function getThrowsException(): ?string
    {
        return $this->throwsException;
    }

    public function getExceptionMessage(): ?string
    {
        return $this->exceptionMessage;
    }
}
