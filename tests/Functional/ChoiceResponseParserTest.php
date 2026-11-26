<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Choice\ChoiceResponseParser;
use Mika\TestGeneratorBundle\Functional\Exception\ChoiceException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChoiceResponseParser::class)]
final class ChoiceResponseParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function readableProvider(): iterable
    {
        yield 'JSON simple' => ['{"choice": 2}', 1];
        yield 'premier candidat' => ['{"choice":1}', 0];
        yield 'aucun choix' => ['{"choice": null}', null];
        yield 'zéro vaut aucun' => ['{"choice": 0}', null];
        yield 'bloc Markdown' => ["```json\n{\"choice\": 3}\n```", 2];
        yield 'ouverture PHP laissée par le client' => ["<?php\n{\"choice\": 3}", 2];
        yield 'guillemets simples' => ["{'choice': '2'}", 1];
        yield 'clé française' => ['choix: 2', 1];
        yield 'nombre seul' => ['3', 2];
        yield 'nombre avec point' => ['2.', 1];
        yield 'mot aucun' => ['aucun', null];
        yield 'null seul' => ['null', null];
        yield 'réponse enrobée par PhpCodeExtractor (ouverture PHP + bloc Markdown)' => ["<?php\n\n```json\n{\"choice\": 2}\n```", 1];
        yield 'réponse nue enrobée par PhpCodeExtractor' => ["<?php\n\n{\"choice\": 1}", 0];
        yield 'texte autour' => ['Voici ma réponse : {"choice": 2} merci', 1];
    }

    #[Test]
    #[DataProvider('readableProvider')]
    public function testParsesTheChoice(string $response, ?int $expected): void
    {
        // ÉTANT DONNÉ une réponse de LLM sur trois candidats
        // QUAND on la lit
        // ALORS on obtient l'indice du candidat, ou null
        self::assertSame($expected, (new ChoiceResponseParser())->parse($response, 3));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableProvider(): iterable
    {
        yield 'prose' => ['Je pense que le bouton « Modifier » est le bon.'];
        yield 'vide' => [''];
        yield 'hors liste' => ['{"choice": 9}'];
        yield 'JSON sans clé' => ['{"answer": 2}'];
    }

    #[Test]
    #[DataProvider('unreadableProvider')]
    public function testRejectsAnUnreadableOrOutOfRangeAnswer(string $response): void
    {
        // ÉTANT DONNÉ une réponse inexploitable
        // QUAND on la lit
        // ALORS une ChoiceException est levée
        $this->expectException(ChoiceException::class);

        (new ChoiceResponseParser())->parse($response, 3);
    }

    #[Test]
    public function testTheErrorQuotesTheStartOfTheAnswer(): void
    {
        // ÉTANT DONNÉ une longue réponse sans choix
        $response = str_repeat('blabla ', 100);

        // QUAND on la lit
        // ALORS le message cite un extrait tronqué
        try {
            (new ChoiceResponseParser())->parse($response, 3);
            self::fail('Une ChoiceException était attendue.');
        } catch (ChoiceException $e) {
            self::assertStringContainsString('blabla blabla', $e->getMessage());
            self::assertStringContainsString('…', $e->getMessage());
            self::assertLessThan(320, mb_strlen($e->getMessage()));
        }
    }
}
