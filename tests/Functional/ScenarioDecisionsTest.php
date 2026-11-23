<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Dto\ScannedElement;
use Mika\TestGeneratorBundle\Functional\Dto\ScenarioDecisions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScenarioDecisions::class)]
final class ScenarioDecisionsTest extends TestCase
{
    #[Test]
    public function testEmptyDecisionsSerializeToAnEmptyJsonObject(): void
    {
        // ÉTANT DONNÉ aucune décision
        $decisions = new ScenarioDecisions();

        // QUAND on les sérialise
        // ALORS on obtient un objet JSON (pas un tableau) et elles sont vides
        self::assertTrue($decisions->isEmpty());
        self::assertSame('{}', $decisions->toJson());
        self::assertTrue(ScenarioDecisions::fromJson('{}')->isEmpty());
        self::assertNull($decisions->get(1));
    }

    #[Test]
    public function testRoundTripKeepsElementsAndSortsStepIndexes(): void
    {
        // ÉTANT DONNÉ deux décisions ajoutées dans le désordre
        $decisions = (new ScenarioDecisions())
            ->with(5, new ScannedElement(tag: 'button', text: 'Enregistrer', selector: '#save'))
            ->with(2, new ScannedElement(tag: 'input', id: 'price', selector: '#price', container: '#panel', relative: '#price'));

        // QUAND on les sérialise puis les relit
        $copy = ScenarioDecisions::fromJson($decisions->toJson());

        // ALORS elles sont identiques et triées par étape
        self::assertSame([2, 5], $copy->stepIndexes());
        self::assertSame('#save', $copy->get(5)?->selector);
        self::assertSame('#panel', $copy->get(2)?->container);
        self::assertFalse($copy->isEmpty());
    }

    #[Test]
    public function testWithReplacesTheDecisionOfTheSameStepWithoutMutatingTheOriginal(): void
    {
        // ÉTANT DONNÉ une décision pour l'étape 1
        $original = (new ScenarioDecisions())->with(1, new ScannedElement(tag: 'a', selector: '#old'));

        // QUAND on la remplace
        $updated = $original->with(1, new ScannedElement(tag: 'a', selector: '#new'));

        // ALORS la nouvelle valeur l'emporte et l'original est intact
        self::assertSame('#new', $updated->get(1)?->selector);
        self::assertSame('#old', $original->get(1)?->selector);
    }

    #[Test]
    public function testMalformedJsonIsRejected(): void
    {
        // ÉTANT DONNÉ un JSON invalide
        // QUAND on le relit
        // ALORS une JsonException est levée
        $this->expectException(\JsonException::class);

        ScenarioDecisions::fromJson('{oops');
    }
}
