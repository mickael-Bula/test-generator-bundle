<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Util;

use Mika\TestGeneratorBundle\Dto\GeneratedTestResult;
use Mika\TestGeneratorBundle\Util\JsonSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

#[CoversClass(JsonSanitizer::class)]
#[CoversClass(GeneratedTestResult::class)]
class JsonSanitizerTest extends TestCase
{
    private JsonSanitizer $sanitizer;
    private Serializer $serializer;

    protected function setUp(): void
    {
        $this->sanitizer = new JsonSanitizer();

        // Configuration complète du Serializer pour prendre en compte #[SerializedName]
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $metadataAwareNameConverter = new MetadataAwareNameConverter($classMetadataFactory);

        $normalizer = new ObjectNormalizer(
            classMetadataFactory: $classMetadataFactory,
            nameConverter: $metadataAwareNameConverter
        );

        $this->serializer = new Serializer([$normalizer], [new JsonEncoder()]);
    }

    /**
     * @throws ExceptionInterface
     */
    #[Test]
    public function testSanitizeLlmJsonResponseWithComplexPhpClass(): void
    {
        // ÉTANT DONNÉ
        $rawLlmResponse = <<<'JSON'
{
  "test_code": "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests\\Dynamic;\n\nuse App\\Command\\GenerateTestCommand;\nuse PHPUnit\\Framework\\TestCase;\n\n#[CoversClass(GenerateTestCommand::class)]\nclass GenerateTestCommandDynamicTest extends TestCase\n{\n}\n"
}
JSON;

        // QUAND
        $sanitizedJson = $this->sanitizer->sanitizeLlmJsonResponse($rawLlmResponse);

        /** @var GeneratedTestResult $dto */$dto = $this->serializer->deserialize($sanitizedJson, GeneratedTestResult::class, 'json');

        // ALORS
        $this->assertJson($sanitizedJson);
        $this->assertInstanceOf(GeneratedTestResult::class,$dto);
        $this->assertStringContainsString('class GenerateTestCommandDynamicTest extends TestCase',$dto->testCode);
        $this->assertStringContainsString('namespace App\Tests\Dynamic;',$dto->getCleanTestCode());
    }

    /**
     * @throws ExceptionInterface
     */
    #[Test]
    public function testSanitizeAndCleanTestCodeWithEscapedSingleQuotes(): void
    {
        // ÉTANT DONNÉ une réponse JSON LLM contenant des chaînes PHP à guillemets simples avec apostrophes échappées
        $rawLlmResponse = <<<'JSON'
{
  "test_code": "<?php\n\n$this->assertStringContainsString('PHPUnit n\\'est pas installé sur le projet hôte.', $display);\n$this->assertStringContainsString('Vous ne pouvez pas spécifier à la fois --unit (-u) et --functional (-f).', $display);\n"
}
JSON;

        // QUAND
        $sanitizedJson = $this->sanitizer->sanitizeLlmJsonResponse($rawLlmResponse);

        /** @var GeneratedTestResult $dto */$dto = $this->serializer->deserialize($sanitizedJson, GeneratedTestResult::class, 'json');

        $cleanCode =$dto->getCleanTestCode();

        // ALORS les chaînes délimitées par ' avec \' doivent être converties en guillemets doubles ".
        $this->assertStringContainsString(
            '$this->assertStringContainsString("PHPUnit n\'est pas installé sur le projet hôte.", $display);',$cleanCode
        );
        $this->assertStringContainsString(
            '$this->assertStringContainsString(\'Vous ne pouvez pas spécifier à la fois --unit (-u) et --functional (-f).\', $display);',$cleanCode
        );
    }

    /**
     * @throws ExceptionInterface
     */
    #[Test]
    public function testCleanTestCodeNormalizesMarkdownAndBackslashes(): void
    {
        // ÉTANT DONNÉ du code PHP entouré de balises Markdown et contenant de doubles échappements d'antislashs
        $rawLlmResponse = <<<'JSON'
{
  "test_code": "```php\n<?php\n\nuse \\\\InvalidArgumentException;\n\nclass Foo {}\n```"
}
JSON;

        // QUAND
        $sanitizedJson = $this->sanitizer->sanitizeLlmJsonResponse($rawLlmResponse);

        /** @var GeneratedTestResult $dto */$dto = $this->serializer->deserialize($sanitizedJson, GeneratedTestResult::class, 'json');

        $cleanCode =$dto->getCleanTestCode();

        // ALORS le balisage markdown est retiré et les antislashs sont normalisés
        $this->assertStringNotContainsString('```', $cleanCode);
        $this->assertStringContainsString('use \InvalidArgumentException;', $cleanCode);
    }
}