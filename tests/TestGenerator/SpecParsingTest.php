<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator;

use Mika\TestGeneratorBundle\Exception\TestGenerationException;
use Mika\TestGeneratorBundle\Llm\SymfonyAiClient;
use Mika\TestGeneratorBundle\Reflection\ObjectExporter;
use Mika\TestGeneratorBundle\Util\JsonSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(SymfonyAiClient::class)]
final class SpecParsingTest extends TestCase
{
    private const RAW = <<<'JSON'
{
  "targetClass": "App\\Service\\Demo",
  "testType": "Unit",
  "dependenciesToMock": [],
  "methods": [
    {
      "name": "calculateSpread",
      "dataProviders": [],
      "testCases": [
        {
          "id": "TC_01",
          "title": "testCalculateSpreadReturnsDefault",
          "description": "Spread par défaut.",
          "type": "success",
          "usesDataProvider": false,
          "dataProviderName": null,
          "inputs": {"entrypoint": {"user": null}},
          "mockExpectations": [],
          "expectedBehavior": {
            "returnValue": 2 / 100,
            "throwsException": null,
            "exceptionMessage": ""
          }
        }
      ]
    }
  ]
}
JSON;

    /**
     * parseSpec() n'appelle aucune plateforme : un localisateur vide suffit, sans kernel.
     */
    private function client(): SymfonyAiClient
    {
        return new SymfonyAiClient(new ServiceLocator([]), new JsonSanitizer());
    }

    /**
     * @throws TestGenerationException
     */
    #[Test]
    public function bareExpressionInAnLlmResponseIsRepairedAndHydrated(): void
    {
        // QUAND
        $dto = $this->client()->parseSpec(self::RAW);

        // ALORS
        self::assertSame('App\Service\Demo', $dto->getTargetClass());
        self::assertCount(1, $dto->getMethods());
        self::assertSame(
            '2 / 100',
            ObjectExporter::toArray($dto)['methods'][0]['testCases'][0]['expectedBehavior']['returnValue'],
        );
    }

    #[Test]
    public function unrepairableJsonIsReportedAsATestGenerationException(): void
    {
        // ALORS
        $this->expectException(TestGenerationException::class);
        $this->expectExceptionMessage('JSON invalide');

        // QUAND
        $this->client()->parseSpec('{"targetClass": ');
    }

    #[Test]
    public function responseWithoutMethodsIsRejected(): void
    {
        // ALORS
        $this->expectException(TestGenerationException::class);
        $this->expectExceptionMessage('structure de spec attendue');

        // QUAND
        $this->client()->parseSpec('{"targetClass": "App\\\\Service\\\\Demo"}');
    }
}
