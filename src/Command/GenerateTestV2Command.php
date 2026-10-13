<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command;

use Mika\TestGeneratorBundle\Dto\TestTargetPath;
use Mika\TestGeneratorBundle\Enum\TestType;
use Mika\TestGeneratorBundle\Exception\PhpStanNotFoundException;
use Mika\TestGeneratorBundle\Exception\TestCorrectionException;
use Mika\TestGeneratorBundle\Llm\LlmClientFactory;
use Mika\TestGeneratorBundle\Manager\SpecManager;
use Mika\TestGeneratorBundle\Resolver\ClassResolver;
use Mika\TestGeneratorBundle\Resolver\TestPathResolver;
use Mika\TestGeneratorBundle\Service\TestGeneratorV2;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'llm:generate:test:v2',
    description: 'Génération de tests PHPUnit avec extraction ciblée et intercepteurs par Réflexion.',
)]
class GenerateTestV2Command extends Command
{
    private SymfonyStyle $io;
    private string $normalizedProjectDir;

    public function __construct(
        private readonly TestGeneratorV2                            $testGenerator,
        private readonly LlmClientFactory                           $llmFactory,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly ClassResolver                              $classResolver,
        private readonly TestPathResolver                           $pathResolver,
        private readonly SpecManager                                $specManager,
        private readonly DatabaseResolverCommand                    $databaseResolverCommand,
    )
    {
        parent::__construct();

        $this->normalizedProjectDir = rtrim(str_replace('\\', '/', $this->projectDir), '/');
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'class',
                InputArgument::REQUIRED,
                'Le nom de la classe à tester, ou son chemin, ou son namespace.'
            )
            ->addOption(
                'method',
                'm',
                InputOption::VALUE_REQUIRED,
                'Cibler une méthode spécifique de la classe à tester'
            )
            ->addOption(
                'model',
                null,
                InputOption::VALUE_OPTIONAL,
                'Modèle LLM spécifique à utiliser',
            )
            ->addOption(
                'spec',
                's',
                InputOption::VALUE_REQUIRED,
                'Chemin vers un fichier de spécification (.md) spécifique.'
            )
            ->addOption(
                'unit',
                'u',
                InputOption::VALUE_NONE,
                'Générer un test unitaire (par défaut)'
            )
            ->addOption(
                'functional',
                'f',
                InputOption::VALUE_NONE,
                'Générer un test fonctionnel'
            );
    }

    /**
     * @throws ExceptionInterface
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $timezone = new \DateTimeZone('Europe/Paris');
        $startTime = new \DateTimeImmutable('now', $timezone);
        $startMicrotime = microtime(true);

        $this->io = new SymfonyStyle($input, $output);
        $this->io->text(sprintf('Début d\'exécution (V2) : <info>%s</info>', $startTime->format('H:i:s')));

        $targetInput = $input->getArgument('class');

        // 1. Vérifications de base (PHPUnit & Config)
        $phpunitBinary = $this->projectDir . '/vendor/bin/phpunit';
        if (!file_exists($phpunitBinary) && !file_exists($phpunitBinary . '.bat')) {
            $this->io->error('PHPUnit n\'est pas installé sur le projet hôte.');
            return Command::FAILURE;
        }

        try {
            $isUnit = (bool)$input->getOption('unit');
            $isFunctional = (bool)$input->getOption('functional');

            if ($isUnit && $isFunctional) {
                $this->io->error('Vous ne pouvez pas spécifier à la fois --unit et --functional.');
                return Command::FAILURE;
            }

            $testType = $isFunctional ? TestType::FUNCTIONAL : TestType::UNIT;

            if ($testType === TestType::FUNCTIONAL) {
                $this->io->section('Vérification de la base de données de test');
                $dbInput = new ArrayInput([]);
                if ($this->databaseResolverCommand->run($dbInput, $output) !== Command::SUCCESS) {
                    $this->io->error('La configuration de l\'environnement de test fonctionnel a échoué.');
                    return Command::FAILURE;
                }
            }

            $resolved = $this->classResolver->resolve($targetInput);
            $fqcn = $resolved->className;
            $filePath = $resolved->filePath;

            if (!file_exists($filePath)) {
                $this->io->error(sprintf('Le fichier "%s" n\'existe pas.', $filePath));
                return Command::FAILURE;
            }

            $classCode = file_get_contents($filePath);
            $this->io->note(sprintf('Classe ciblée (V2) : %s (%s)', $fqcn, $filePath));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->io->error($e->getMessage());
            return Command::FAILURE;
        }

        $shortClassName = basename(str_replace('\\', '/', $fqcn));
        $model = $input->getOption('model') ?? $this->llmFactory->getDefaultModel();
        $provider = $this->llmFactory->getDefaultProvider();
        $customSpecPath = $input->getOption('spec');

        // Chargement ou génération de la spec métier
        try {
            $specContent = $this->specManager->resolveOrGenerateSpecContent(
                shortClassName: $shortClassName,
                classCode: $classCode,
                fqcn: $fqcn,
                methodName: $input->getOption('method'),
                testType: $testType->value,
                model: $model,
                provider: $provider,
                customPath: $customSpecPath
            );
        } catch (\Throwable $e) {
            $this->io->warning(sprintf('Poursuite sans spec : %s', $e->getMessage()));
            $specContent = null;
        }

        $methodName = $input->getOption('method');

        $this->io->title(sprintf('Analyse (Extraction ciblée + Réflexion) et test %s pour : %s', $testType->label(), $shortClassName));

        try {
            $testTargetPath = $this->pathResolver->resolve($fqcn, $shortClassName, $testType);

            if (
                !is_dir($testTargetPath->targetDirectory)
                && !mkdir($testTargetPath->targetDirectory, 0777, true)
                && !is_dir($testTargetPath->targetDirectory)
            ) {
                throw new \RuntimeException(sprintf('Le dossier "%s" n\'a pas été créé', $testTargetPath->targetDirectory));
            }

            $existingTestCode = null;
            if (file_exists($testTargetPath->filePath)) {
                $this->io->note('Un fichier de test existant a été détecté pour fusion.');
                $existingTestCode = file_get_contents($testTargetPath->filePath);
                $existingTestCode = $this->testGenerator->replaceDynamicHeadersInExistingTestCode(
                    existingTestCode: $existingTestCode,
                    targetNamespace: $testTargetPath->targetNamespace,
                    className: $shortClassName,
                );
            }

            $this->io->comment(sprintf('Envoi du code au LLM via la nouvelle architecture V2 (%s)...', $model));

            // Appel au TestGeneratorV2 équipé des intercepteurs par Réflexion et extraction ciblée
            $testCode = $this->testGenerator->generateForClass(
                classCode: $classCode,
                filePath: $filePath,
                fqcn: $fqcn,
                className: $shortClassName,
                model: $model,
                methodName: $methodName,
                existingTestCode: $existingTestCode,
                specContent: $specContent,
                type: $testType->value,
                provider: $provider
            );

            $testCode = $this->testGenerator->replaceDynamicHeadersInTestCode(
                testCode: $testCode,
                targetNamespace: $testTargetPath->targetNamespace,
                className: $shortClassName,
            );

            $cleanTestCode = $this->refactorFqcnToUseStatements($testCode);
            file_put_contents($testTargetPath->filePath, $cleanTestCode);

            $this->io->success(
                sprintf(
                    'Test V2 généré avec succès dans : %s',
                    str_replace($this->normalizedProjectDir . '/', '', $testTargetPath->filePath)
                )
            );
            $this->displayExecutionTime($startTime, $startMicrotime);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->io->error('Erreur lors de la génération V2 : ' . $e->getMessage());
            $this->displayExecutionTime($startTime, $startMicrotime);

            return Command::FAILURE;
        }
    }

    private function displayExecutionTime(\DateTimeImmutable $startTime, float $startMicrotime): void
    {
        $durationInSeconds = round(microtime(true) - $startMicrotime, 2);
        $this->io->newLine();
        $this->io->definitionList(
            ['Début' => $startTime->format('H:i:s')],
            ['Durée totale' => sprintf('%.2fs', $durationInSeconds)]
        );
    }

    private function refactorFqcnToUseStatements(string $phpCode): string
    {
        preg_match_all('/\\\\(App|Doctrine|PHPUnit\\\\Framework\\\\MockObject)\\\\[a-zA-Z0-9_\\\\]+/', $phpCode, $matches);
        $fqcns = array_unique($matches[0]);

        if (empty($fqcns)) {
            return $phpCode;
        }

        $useStatements = [];
        $replacements = [];

        foreach ($fqcns as $fqcn) {
            $parts = explode('\\', ltrim($fqcn, '\\'));
            $shortName = end($parts);
            $useStatements[] = "use " . ltrim($fqcn, '\\') . ";";
            $replacements[$fqcn] = $shortName;
        }

        $useStatements = array_unique($useStatements);
        sort($useStatements);
        $useBlock = implode("\n", $useStatements);

        uksort($replacements, fn($a, $b) => strlen($b) - strlen($a));
        foreach ($replacements as $fqcn => $shortName) {
            $phpCode = str_replace($fqcn, $shortName, $phpCode);
        }

        if (preg_match('/namespace\s+[^;]+;\s*/', $phpCode, $nsMatches)) {
            $phpCode = str_replace($nsMatches[0], $nsMatches[0] . "\n" . $useBlock . "\n", $phpCode);
        }

        return $phpCode;
    }
}