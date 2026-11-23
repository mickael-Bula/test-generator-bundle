<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Dto;

final readonly class ScanResult
{
    /**
     * @param list<Panel>          $panels   tous les panneaux de la page, ouverts ou non
     * @param list<ScannedElement> $elements éléments interactifs visibles (dans le panneau ouvert s'il y en a un)
     */
    public function __construct(
        public string $url,
        public ?Panel $container,
        public array $panels,
        public array $elements,
        public string $bodyText,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $panels = [];
        foreach ((array) ($data['panels'] ?? []) as $panel) {
            if (is_array($panel)) {
                $panels[] = Panel::fromArray($panel);
            }
        }

        $elements = [];
        foreach ((array) ($data['elements'] ?? []) as $element) {
            if (is_array($element)) {
                $elements[] = ScannedElement::fromArray($element);
            }
        }

        return new self(
            (string) ($data['url'] ?? '/'),
            isset($data['container']) && is_array($data['container']) ? Panel::fromArray($data['container']) : null,
            $panels,
            $elements,
            (string) ($data['bodyText'] ?? ''),
        );
    }

    public function path(): string
    {
        $path = strtok($this->url, '?');

        return false === $path || '' === $path ? '/' : $path;
    }
}
