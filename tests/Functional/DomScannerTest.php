<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Exception\ProbeException;
use Mika\TestGeneratorBundle\Functional\Probe\DomScanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DomScanner::class)]
final class DomScannerTest extends TestCase
{
    #[Test]
    public function testParsesARealScanOfTheOpenPanel(): void
    {
        // ÉTANT DONNÉ le JSON d'un vrai balayage du panneau ouvert
        $json = (string) file_get_contents(__DIR__ . '/Fixture/scan-new-position-open.json');

        // QUAND on le convertit
        $scan = DomScanner::parse($json);

        // ALORS le conteneur, les panneaux et les éléments sont typés
        self::assertSame('/', $scan->path());
        self::assertNotNull($scan->container);
        self::assertSame('#offcanvasNewPositionwaiting', $scan->container->selector);
        self::assertSame('Nouvelle Position en attente', $scan->container->title);
        self::assertCount(2, $scan->panels);
        self::assertCount(11, $scan->elements);
        self::assertSame('textbox', $scan->elements[1]->kind());
        self::assertSame('Prix Achat CAC', $scan->elements[1]->nearby);
        self::assertSame('#position_buyPrice', $scan->elements[1]->relative);
        self::assertSame('checkbox', $scan->elements[8]->kind());
        self::assertSame('select', $scan->elements[9]->kind());
        self::assertCount(3, $scan->elements[9]->options);
    }

    #[Test]
    public function testParsesAnAlreadyDecodedArray(): void
    {
        // ÉTANT DONNÉ la réponse déjà décodée de Page::evaluate
        $raw = json_decode((string) file_get_contents(__DIR__ . '/Fixture/scan-dashboard.json'), true, 512, JSON_THROW_ON_ERROR);

        // QUAND on la convertit
        $scan = DomScanner::parse($raw);

        // ALORS aucun panneau n'est ouvert
        self::assertNull($scan->container);
        self::assertCount(11, $scan->elements);
    }

    #[Test]
    public function testRejectsAResponseWithoutElements(): void
    {
        // ÉTANT DONNÉ une réponse inattendue (la fonction n'a pas été appelée : evaluate renvoie null)
        // QUAND on la convertit
        // ALORS une exception explicite est levée
        $this->expectException(ProbeException::class);
        $this->expectExceptionMessage('structure attendue');

        DomScanner::parse(null);
    }

    #[Test]
    public function testRejectsInvalidJson(): void
    {
        // ÉTANT DONNÉ une chaîne qui n'est pas du JSON
        // QUAND on la convertit
        // ALORS une exception explicite est levée
        $this->expectException(ProbeException::class);
        $this->expectExceptionMessage('illisible');

        DomScanner::parse('{pas du json');
    }

    #[Test]
    public function testScriptIsAFunctionExpression(): void
    {
        // ÉTANT DONNÉ le script de balayage
        $script = DomScanner::SCRIPT;

        // QUAND on l'examine
        // ALORS c'est une fonction fléchée sans paramètre, que Page::evaluate peut appeler
        self::assertStringStartsWith('() =>', $script);
        self::assertStringNotContainsString('document.write', $script);
    }
}
