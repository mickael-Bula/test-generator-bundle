<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Dto\ScannedElement;
use Mika\TestGeneratorBundle\Functional\Text\ElementDescriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ElementDescriber::class)]
final class ElementDescriberTest extends TestCase
{
    #[Test]
    public function testDescribesOnlyThePresentAttributes(): void
    {
        // ÉTANT DONNÉ un champ avec peu d'attributs
        $element = new ScannedElement(tag: 'input', type: 'text', id: 'position_buyPrice', nearby: 'Prix Achat CAC', selector: '#position_buyPrice');

        // QUAND on le décrit
        $description = ElementDescriber::describe($element);

        // ALORS seuls les attributs présents apparaissent, le sélecteur en dernier
        self::assertSame('<input type=text> · texte voisin « Prix Achat CAC » · id=position_buyPrice · sélecteur #position_buyPrice', $description);
    }

    #[Test]
    public function testMentionsDisabledOptionsAndPanelTitle(): void
    {
        // ÉTANT DONNÉ un bouton désactivé qui ouvre un panneau, et une liste
        $button = new ScannedElement(tag: 'button', targetTitle: 'Nouvelle Position', disabled: true, selector: '#b');
        $select = new ScannedElement(tag: 'select', options: [['value' => 'a', 'text' => 'A'], ['value' => 'b', 'text' => 'B']], selector: '#s');

        // QUAND on les décrit sans sélecteur
        // ALORS le titre du panneau, l'état désactivé et le nombre d'options sont indiqués
        self::assertSame('<button> · titre du panneau ouvert « Nouvelle Position » · désactivé', ElementDescriber::describe($button, false));
        self::assertSame('<select> · 2 option(s)', ElementDescriber::describe($select, false));
    }

    #[Test]
    public function testShortensVeryLongValues(): void
    {
        // ÉTANT DONNÉ un texte de 300 caractères
        $element = new ScannedElement(tag: 'a', text: str_repeat('x', 300), selector: '#a');

        // QUAND on le décrit
        $description = ElementDescriber::describe($element, false);

        // ALORS il est tronqué avec une ellipse
        self::assertStringContainsString(str_repeat('x', 99) . '…', $description);
        self::assertLessThan(150, mb_strlen($description));
    }
}
