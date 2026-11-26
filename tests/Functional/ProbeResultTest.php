<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Dto\PendingDecision;
use Mika\TestGeneratorBundle\Functional\Dto\ProbeResult;
use Mika\TestGeneratorBundle\Functional\Dto\ResolvedExpectation;
use Mika\TestGeneratorBundle\Functional\Dto\ResolvedStep;
use Mika\TestGeneratorBundle\Functional\Dto\ScannedElement;
use Mika\TestGeneratorBundle\Functional\Dto\Target;
use Mika\TestGeneratorBundle\Functional\Enum\ProbeStatus;
use Mika\TestGeneratorBundle\Functional\Enum\ResolutionStatus;
use Mika\TestGeneratorBundle\Functional\Exception\ProbeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProbeResult::class)]
#[CoversClass(ScannedElement::class)]
final class ProbeResultTest extends TestCase
{
    #[Test]
    public function testSuccessfulResultSurvivesJsonRoundTrip(): void
    {
        // ÉTANT DONNÉ le résultat d'une sonde réussie
        $result = ProbeResult::ok(
            [
                new ResolvedStep(1, new Target('button[data-bs-target="#oc"]', 'button[data-bs-target="#oc"]', null, false), null, '#oc', null, null),
                new ResolvedStep(9, new Target('#oc button[type="submit"]', 'button[type="submit"]', '#oc', false), null, null, '#oc', null),
            ],
            [new ResolvedExpectation(1, '#oc')],
        );

        // QUAND on le sérialise puis le relit (le processus de sonde et la commande échangent du JSON)
        $decoded = ProbeResult::fromArray(json_decode($result->toJson(), true, 512, JSON_THROW_ON_ERROR));

        // ALORS rien n'est perdu
        self::assertSame(ProbeStatus::Ok, $decoded->status);
        self::assertCount(2, $decoded->steps);
        self::assertSame('#oc', $decoded->stepFor(1)?->opensContainer);
        self::assertSame('#oc', $decoded->stepFor(9)?->closesContainer);
        self::assertSame('#oc', $decoded->stepFor(9)?->target?->container);
        self::assertSame('button[type="submit"]', $decoded->stepFor(9)?->target?->relative);
        self::assertSame('#oc', $decoded->expectationFor(1)?->panelSelector);
        self::assertNull($decoded->stepFor(42));
        self::assertNull($decoded->expectationFor(42));
    }

    #[Test]
    public function testPendingDecisionKeepsItsCandidates(): void
    {
        // ÉTANT DONNÉ une sonde interrompue par une étape ambiguë
        $pending = new PendingDecision(3, ResolutionStatus::Ambiguous, [
            ScannedElement::fromArray(['tag' => 'input', 'type' => 'text', 'name' => 'a', 'nearby' => 'Prix', 'selector' => '#a']),
            ScannedElement::fromArray(['tag' => 'select', 'options' => [['value' => 'x', 'text' => 'X']], 'selector' => '#b']),
        ]);
        $result = ProbeResult::needsDecision([], $pending);

        // QUAND on le sérialise puis le relit
        $decoded = ProbeResult::fromArray(json_decode($result->toJson(), true, 512, JSON_THROW_ON_ERROR));

        // ALORS l'étape, le statut et les candidats sont conservés
        self::assertSame(ProbeStatus::NeedsDecision, $decoded->status);
        self::assertSame(3, $decoded->pending?->stepIndex);
        self::assertSame(ResolutionStatus::Ambiguous, $decoded->pending?->status);
        self::assertCount(2, $decoded->pending->candidates);
        self::assertSame('Prix', $decoded->pending->candidates[0]->nearby);
        self::assertSame('x', $decoded->pending->candidates[1]->options[0]['value']);
    }

    #[Test]
    public function testFailedResultCarriesItsError(): void
    {
        // ÉTANT DONNÉ une sonde en échec
        $result = ProbeResult::failed([], 'Page inaccessible');

        // QUAND on la sérialise puis la relit
        $decoded = ProbeResult::fromArray(json_decode($result->toJson(), true, 512, JSON_THROW_ON_ERROR));

        // ALORS l'erreur est conservée
        self::assertSame(ProbeStatus::Failed, $decoded->status);
        self::assertSame('Page inaccessible', $decoded->error);
    }

    #[Test]
    public function testElementKindFollowsTagTypeAndRole(): void
    {
        // ÉTANT DONNÉ des éléments de natures différentes
        $kinds = [
            ['a', null, null, 'link'],
            ['select', null, null, 'select'],
            ['textarea', null, null, 'textbox'],
            ['input', null, null, 'textbox'],
            ['input', 'date', null, 'textbox'],
            ['input', 'checkbox', null, 'checkbox'],
            ['input', 'radio', null, 'radio'],
            ['input', 'submit', null, 'button'],
            ['input', 'file', null, 'file'],
            ['button', 'submit', null, 'button'],
            ['div', null, 'button', 'button'],
            ['div', null, 'switch', 'checkbox'],
            ['span', null, 'link', 'link'],
            ['div', null, null, 'other'],
        ];

        foreach ($kinds as [$tag, $type, $role, $expected]) {
            // QUAND on demande leur famille
            $element = ScannedElement::fromArray(['tag' => $tag, 'type' => $type, 'role' => $role]);

            // ALORS elle correspond
            self::assertSame($expected, $element->kind(), sprintf('%s type=%s role=%s', $tag, (string) $type, (string) $role));
        }
    }

    #[Test]
    public function testFromJsonRebuildsAResultWrittenByToJson(): void
    {
        // ÉTANT DONNÉ un résultat avec une étape, une attente et un avertissement
        $result = ProbeResult::ok(
            [new ResolvedStep(1, new Target('#a', '#a', '#p', false), null, '#p', null, '/x')],
            [new ResolvedExpectation(1, '#p')],
            ['avertissement'],
        );

        // QUAND on le sérialise puis le relit
        $copy = ProbeResult::fromJson($result->toJson());

        // ALORS il est identique
        self::assertEquals($result, $copy);
    }

    #[Test]
    public function testFromJsonRejectsGarbageAndUnknownStatuses(): void
    {
        // ÉTANT DONNÉ un JSON invalide puis un statut inconnu
        foreach (['{oops', '[]', '{"status":"perhaps"}', '"text"'] as $json) {
            // QUAND on les relit
            try {
                ProbeResult::fromJson($json);
                self::fail('Une ProbeException était attendue pour : ' . $json);
            } catch (ProbeException) {
                // ALORS chacun est refusé avec une exception dédiée
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function testPendingDecisionKeepsThePageItStoppedOnThroughJson(): void
    {
        // ÉTANT DONNÉ une décision en attente sur la page /login
        $pending = new PendingDecision(2, ResolutionStatus::NotFound, [new ScannedElement(tag: 'button', selector: '#go')], '/login');

        // QUAND le résultat est sérialisé puis relu
        $copy = ProbeResult::fromJson(ProbeResult::needsDecision([], $pending)->toJson());

        // ALORS la page est conservée ; sans page, elle reste nulle
        self::assertSame('/login', $copy->pending?->path);
        $without = ProbeResult::fromJson(ProbeResult::needsDecision([], new PendingDecision(1, ResolutionStatus::Ambiguous, []))->toJson());
        self::assertNull($without->pending?->path);
    }
}
