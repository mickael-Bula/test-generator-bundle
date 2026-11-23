<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Text;

use Mika\TestGeneratorBundle\Functional\Dto\ScannedElement;

/**
 * Description d'un élément de page sur une ligne, pour le prompt de choix et pour le compte rendu.
 */
final class ElementDescriber
{
    private const MAX_VALUE_LENGTH = 100;

    public static function describe(ScannedElement $element, bool $withSelector = true): string
    {
        $tag = null !== $element->type && 'input' === $element->tag ? sprintf('<input type=%s>', $element->type) : sprintf('<%s>', $element->tag);
        $parts = [$tag];

        $quoted = [
            'texte' => $element->text,
            'label' => $element->label,
            'texte voisin' => $element->nearby,
            'texte qui suit' => $element->following,
            'aria-label' => $element->ariaLabel,
            'placeholder' => $element->placeholder,
            'title' => $element->title,
            'titre du panneau ouvert' => $element->targetTitle,
            'data-testid' => $element->testId,
        ];
        foreach ($quoted as $name => $value) {
            if (null !== $value && '' !== $value) {
                $parts[] = sprintf('%s « %s »', $name, self::shorten($value));
            }
        }

        foreach (['id' => $element->id, 'name' => $element->name, 'href' => $element->href] as $name => $value) {
            if (null !== $value && '' !== $value) {
                $parts[] = sprintf('%s=%s', $name, self::shorten($value));
            }
        }

        if ([] !== $element->options) {
            $parts[] = sprintf('%d option(s)', count($element->options));
        }

        if ($element->disabled) {
            $parts[] = 'désactivé';
        }

        if ($withSelector && '' !== $element->selector) {
            $parts[] = sprintf('sélecteur %s', $element->selector);
        }

        return implode(' · ', $parts);
    }

    private static function shorten(string $value): string
    {
        return mb_strlen($value) > self::MAX_VALUE_LENGTH ? mb_substr($value, 0, self::MAX_VALUE_LENGTH - 1) . '…' : $value;
    }
}
