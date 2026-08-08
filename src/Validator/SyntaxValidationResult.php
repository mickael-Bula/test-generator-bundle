<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Validator;

final readonly class SyntaxValidationResult
{
    private function __construct(
        public bool $isValid,
        public ?string $errorMessage = null,
        public ?int $errorLine = null,
    ) {
    }

    public static function success(): self
    {
        return new self(isValid: true);
    }

    public static function failure(string $message, int $line): self
    {
        return new self(
            isValid: false,
            errorMessage: $message,
            errorLine: $line
        );
    }
}
