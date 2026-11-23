<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Text\TextNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextNormalizer::class)]
final class TextNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function foldProvider(): iterable
    {
        yield 'accents et casse' => ['Date de l’opération', "date de l'operation"];
        yield 'espaces multiples' => ["  Prix   Achat\u{00A0}CAC ", 'prix achat cac'];
        yield 'glyphe d\'icône' => ["\u{F035C} Dashboard", 'dashboard'];
        yield 'glyphe seul' => ["\u{F035C}", ''];
        yield 'euro conservé' => ['Prix Achat LVC (€)', 'prix achat lvc (€)'];
    }

    #[Test]
    #[DataProvider('foldProvider')]
    public function testFoldNormalizesText(string $input, string $expected): void
    {
        // ÉTANT DONNÉ un libellé brut

        // QUAND on le replie
        $folded = TextNormalizer::fold($input);

        // ALORS la forme comparable est stable
        self::assertSame($expected, $folded);
    }

    #[Test]
    public function testKeyIgnoresTrailingDecoration(): void
    {
        // ÉTANT DONNÉ un libellé avec deux-points et étoile de champ obligatoire
        // QUAND on calcule sa clé
        // ALORS la ponctuation décorative finale disparaît
        self::assertSame('quantite', TextNormalizer::key('Quantité * :'));
        self::assertSame('quantite', TextNormalizer::key('Quantité'));
    }

    #[Test]
    public function testCleanKeepsCaseButDropsIcons(): void
    {
        // ÉTANT DONNÉ un texte de lien préfixé par un glyphe d'icône
        // QUAND on le nettoie
        $clean = TextNormalizer::clean("\u{F0F09}  Paramètres ");

        // ALORS la casse et les accents sont conservés
        self::assertSame('Paramètres', $clean);
    }

    #[Test]
    public function testDistanceIsZeroForEquivalentLabelsAndGrowsWithDifference(): void
    {
        // ÉTANT DONNÉ des libellés équivalents et d'autres différents
        // QUAND on mesure la distance
        // ALORS elle est nulle pour les premiers et croissante pour les autres
        self::assertSame(0.0, TextNormalizer::distance('Prix Achat CAC', 'prix achat cac :'));
        self::assertLessThan(0.3, TextNormalizer::distance('Prix Achat CAC', 'Prix Achat LVC'));
        self::assertGreaterThan(0.6, TextNormalizer::distance('Quantité', 'Enregistrer la position'));
        self::assertSame(0.0, TextNormalizer::distance('', ''));
    }
}
