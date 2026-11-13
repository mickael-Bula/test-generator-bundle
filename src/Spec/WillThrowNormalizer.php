<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Spec;

/**
 * Le LLM décrit parfois `willThrow` comme un objet {class, message} au lieu du nom de classe
 * attendu par MockExpectationDto : on le réduit au nom de classe.
 */
final class WillThrowNormalizer
{
    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public function normalize(array $data): array
    {
        foreach ($data as $key => $value) {
            if ('willThrow' === $key) {
                $data[$key] = $this->toClassName($value);
            } elseif (\is_array($value)) {
                $data[$key] = $this->normalize($value);
            }
        }

        return $data;
    }

    private function toClassName(mixed $value): ?string
    {
        if (null === $value || \is_string($value)) {
            return $value;
        }

        if (\is_array($value)) {
            $class = $value['class'] ?? null;
            if (\is_string($class) && '' !== trim($class)) {
                return trim($class);
            }
        }

        return null;
    }
}
