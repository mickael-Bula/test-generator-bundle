<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Dto;

final readonly class Target
{
    /**
     * @param string      $selector  sélecteur CSS complet, unique dans la page
     * @param string      $relative  sélecteur relatif au conteneur (identique à $selector sans conteneur)
     * @param string|null $container sélecteur du panneau ouvert qui contient l'élément
     * @param bool        $fragile   vrai quand aucun attribut stable n'a permis un sélecteur unique
     */
    public function __construct(
        public string $selector,
        public string $relative,
        public ?string $container,
        public bool $fragile,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'selector' => $this->selector,
            'relative' => $this->relative,
            'container' => $this->container,
            'fragile' => $this->fragile,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['selector'] ?? ''),
            (string) ($data['relative'] ?? ($data['selector'] ?? '')),
            isset($data['container']) ? (string) $data['container'] : null,
            (bool) ($data['fragile'] ?? false),
        );
    }
}
