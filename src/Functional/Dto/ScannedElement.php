<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Dto;

final readonly class ScannedElement
{
    /**
     * @param list<array{value: string, text: string}> $options options d'une liste déroulante
     */
    public function __construct(
        public string $tag,
        public ?string $type = null,
        public ?string $role = null,
        public ?string $id = null,
        public ?string $name = null,
        public ?string $placeholder = null,
        public ?string $ariaLabel = null,
        public ?string $title = null,
        public ?string $testId = null,
        public ?string $href = null,
        public ?string $text = null,
        public ?string $label = null,
        public ?string $nearby = null,
        public ?string $following = null,
        public ?string $bsTarget = null,
        public ?string $targetTitle = null,
        public bool $disabled = false,
        public bool $checked = false,
        public array $options = [],
        public string $selector = '',
        public string $relative = '',
        public ?string $container = null,
        public bool $fragile = false,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $options = [];
        foreach ((array) ($data['options'] ?? []) as $option) {
            if (is_array($option)) {
                $options[] = ['value' => (string) ($option['value'] ?? ''), 'text' => (string) ($option['text'] ?? '')];
            }
        }

        $selector = (string) ($data['selector'] ?? '');

        return new self(
            strtolower((string) ($data['tag'] ?? '')),
            self::nullable($data, 'type'),
            self::nullable($data, 'role'),
            self::nullable($data, 'id'),
            self::nullable($data, 'name'),
            self::nullable($data, 'placeholder'),
            self::nullable($data, 'ariaLabel'),
            self::nullable($data, 'title'),
            self::nullable($data, 'testId'),
            self::nullable($data, 'href'),
            self::nullable($data, 'text'),
            self::nullable($data, 'label'),
            self::nullable($data, 'nearby'),
            self::nullable($data, 'following'),
            self::nullable($data, 'bsTarget'),
            self::nullable($data, 'targetTitle'),
            (bool) ($data['disabled'] ?? false),
            (bool) ($data['checked'] ?? false),
            $options,
            $selector,
            (string) ($data['relative'] ?? $selector),
            self::nullable($data, 'container'),
            (bool) ($data['fragile'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tag' => $this->tag,
            'type' => $this->type,
            'role' => $this->role,
            'id' => $this->id,
            'name' => $this->name,
            'placeholder' => $this->placeholder,
            'ariaLabel' => $this->ariaLabel,
            'title' => $this->title,
            'testId' => $this->testId,
            'href' => $this->href,
            'text' => $this->text,
            'label' => $this->label,
            'nearby' => $this->nearby,
            'following' => $this->following,
            'bsTarget' => $this->bsTarget,
            'targetTitle' => $this->targetTitle,
            'disabled' => $this->disabled,
            'checked' => $this->checked,
            'options' => $this->options,
            'selector' => $this->selector,
            'relative' => $this->relative,
            'container' => $this->container,
            'fragile' => $this->fragile,
        ];
    }

    /**
     * Famille fonctionnelle de l'élément : link, button, textbox, checkbox, radio, select, file ou other.
     */
    public function kind(): string
    {
        $type = strtolower((string) $this->type);
        $role = strtolower((string) $this->role);

        return match (true) {
            'a' === $this->tag => 'link',
            'select' === $this->tag => 'select',
            'textarea' === $this->tag => 'textbox',
            'input' === $this->tag && 'checkbox' === $type => 'checkbox',
            'input' === $this->tag && 'radio' === $type => 'radio',
            'input' === $this->tag && in_array($type, ['submit', 'button', 'reset', 'image'], true) => 'button',
            'input' === $this->tag && 'file' === $type => 'file',
            'input' === $this->tag => 'textbox',
            'button' === $this->tag, 'summary' === $this->tag => 'button',
            in_array($role, ['button', 'tab', 'menuitem'], true) => 'button',
            'link' === $role => 'link',
            in_array($role, ['checkbox', 'switch'], true) => 'checkbox',
            'radio' === $role => 'radio',
            default => 'other',
        };
    }

    public function toTarget(): Target
    {
        return new Target($this->selector, '' === $this->relative ? $this->selector : $this->relative, $this->container, $this->fragile);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function nullable(array $data, string $key): ?string
    {
        if (!isset($data[$key]) || !is_scalar($data[$key])) {
            return null;
        }

        $value = (string) $data[$key];

        return '' === $value ? null : $value;
    }
}
