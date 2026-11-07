<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator\Support;

use Mika\TestGeneratorBundle\Interceptor\Support\PreFixerChain;
use Mika\TestGeneratorBundle\Llm\LlmClientFactory;
use Mika\TestGeneratorBundle\PromptBuilder\PhpUnitFixerPromptBuilderV2;
use Mika\TestGeneratorBundle\PromptBuilder\PhpUnitMethodFixerPromptBuilder;
use Mika\TestGeneratorBundle\Quality\CommentPolicy;
use Mika\TestGeneratorBundle\Quality\MethodPatcher;
use Mika\TestGeneratorBundle\Quality\TestQualityAnalyzer;
use Mika\TestGeneratorBundle\Quality\TestQualityGate;
use Mika\TestGeneratorBundle\Registry\ErrorInterceptorRegistry;
use Mika\TestGeneratorBundle\Resolver\CoveredClassReader;
use Mika\TestGeneratorBundle\Service\TestGeneratorV2;
use Mika\TestGeneratorBundle\Service\TestSnapshotStore;
use Mika\TestGeneratorBundle\Validator\PhpSyntaxValidator;
use PhpParser\ParserFactory;
use Random\RandomException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Monte un vrai TestGeneratorV2 avec un faux LLM, un faux PHPUnit et un faux PHPStan, dans un dossier temporaire.
 */
final readonly class GeneratorHarness
{
    public string $dir;
    public ScriptedLlmClient $client;
    public MarkerPhpUnitRunner $runner;
    public MarkerPhpStanRunner $stan;
    public MethodPatcher $patcher;
    public TestQualityGate $qualityGate;
    public TestGeneratorV2 $generator;

    /**
     * @param list<string|callable> $responses réponses du LLM, dans l'ordre des appels
     * @throws RandomException
     */
    public function __construct(array $responses)
    {
        $this->dir = sys_get_temp_dir() . '/mika_generator_' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir([$this->dir . '/snapshots', $this->dir . '/tmp', $this->dir . '/failed_tests']);

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $snapshots = new TestSnapshotStore($this->dir . '/snapshots');
        $registry = new ErrorInterceptorRegistry([]);

        $this->client = new ScriptedLlmClient($responses);
        $this->runner = new MarkerPhpUnitRunner($snapshots);
        $this->stan = new MarkerPhpStanRunner();
        $this->patcher = new MethodPatcher($parser);
        $this->qualityGate = new TestQualityGate(new TestQualityAnalyzer(
            $parser,
            new CoveredClassReader(),
            new CommentPolicy($parser),
        ));

        $this->generator = new TestGeneratorV2(
            llmFactory: new LlmClientFactory([$this->client], ScriptedLlmClient::PROVIDER, 'scripted-model'),
            testRunner: $this->runner,
            syntaxValidator: new PhpSyntaxValidator($parser),
            phpStanRunner: $this->stan,
            preFixer: new PreFixerChain([]),
            qualityGate: $this->qualityGate,
            snapshots: $snapshots,
            patcher: $this->patcher,
            promptBuilders: [
                new PhpUnitMethodFixerPromptBuilder($registry, $this->patcher),
                new PhpUnitFixerPromptBuilderV2($registry),
            ],
            tmpDir: $this->dir . '/tmp',
            failedTestsDir: $this->dir . '/failed_tests',
            trace: false,
        );
    }

    public function cleanup(): void
    {
        (new Filesystem())->remove($this->dir);
    }
}
