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
use Mika\TestGeneratorBundle\Service\TestGenerator;
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
    name: 'llm:generate:test',
    description: 'Génération de tests PHPUnit pour une classe donnée via le LLM configuré, avec validation automatique.',
)]
class GenerateTestCommand extends Command
{
    private SymfonyStyle $io;
    private string $normalizedProjectDir;

    public function __construct(
        private readonly TestGenerator $testGenerator,
        private readonly LlmClientFactory $llmFactory,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly ClassResolver $classResolver,
        private readonly TestPathResolver $pathResolver,
        private readonly SpecManager $specManager,
        private readonly DatabaseResolverCommand $databaseResolverCommand,
    ) {
        parent::__construct();

        $this->normalizedProjectDir = rtrim(str_replace('\\', '/', $this->projectDir), '/');
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'class',
                InputArgument::REQUIRED,
                'Le nom de la classe à tester, '
                . 'ou son chemin (ex: src/Service/CalculatorService.php) '
                . 'ou encore son namespace (ex : \\App\\Service\\Calculator)'
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
                'Modèle LLM spécifique à utiliser (ex: qwen2.5-coder:14b ou un modèle OpenRouter)',
            )
            ->addOption(
                'spec',
                's',
                InputOption::VALUE_REQUIRED,
                'Chemin vers un fichier de spécification (.md) spécifique. Si absent, le fichier par défaut sera lu ou créé.'
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
     * @throws \DateInvalidTimeZoneException|\Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $timezone = new \DateTimeZone('Europe/Paris');
        $startTime = new \DateTimeImmutable('now', $timezone);
        $startMicrotime = microtime(true);

        $this->io = new SymfonyStyle($input, $output);
        $this->io->text(sprintf('Début d\'exécution : <info>%s</info>', $startTime->format('H:i:s')));

        $targetInput = $input->getArgument('class');

        // 1. Vérification de la présence du binaire PHPUnit
        $phpunitBinary = $this->projectDir . '/vendor/bin/phpunit';

        if (!file_exists($phpunitBinary) && !file_exists($phpunitBinary . '.bat')) {
            $this->io->error('PHPUnit n\'est pas installé sur le projet hôte.');
            $this->io->note('Exécutez : composer require --dev phpunit/phpunit');

            return Command::FAILURE;
        }

        // 2. Vérification de la config PHPUnit
        $configFiles = ['phpunit.xml', 'phpunit.xml.dist', 'phpunit.dist.xml'];
        $hasConfig = (bool) array_filter(
            $configFiles,
            fn ($file) => file_exists($this->projectDir . '/' . $file)
        );

        if (!$hasConfig) {
            $lastFile = array_pop($configFiles);
            $formattedList = implode(', ', $configFiles) . ' ou ' . $lastFile;

            $this->io->error(
                sprintf(
                    'Aucun fichier de configuration PHPUnit (%s) n\'a été trouvé à la racine du projet.',
                    $formattedList
                )
            );

            return Command::FAILURE;
        }

        try {
            $isUnit = (bool) $input->getOption('unit');
            $isFunctional = (bool) $input->getOption('functional');

            if ($isUnit && $isFunctional) {
                $this->io->error('Vous ne pouvez pas spécifier à la fois --unit (-u) et --functional (-f).');

                return Command::FAILURE;
            }

            $testType = $isFunctional ? TestType::FUNCTIONAL : TestType::UNIT;

            // Si le test est fonctionnel, on vérifie que la base de données est initialisée
            if ($testType === TestType::FUNCTIONAL) {
                $this->io->section('Vérification de la base de données de test');
                try {
                    $dbInput = new ArrayInput([]);
                    $exitCode = $this->databaseResolverCommand->run($dbInput, $output);

                    if ($exitCode !== Command::SUCCESS) {
                        $this->io->error(' La configuration de l\'environnement de test fonctionnel a échoué.');

                        return Command::FAILURE;
                    }
                } catch (ExceptionInterface $e) {
                    $this->io->error($e->getMessage());
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
            $this->io->note(sprintf('Classe ciblée : %s (%s)', $fqcn, $filePath));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }

        $shortClassName = basename(str_replace('\\', '/', $fqcn));
        $model = $input->getOption('model') ?? $this->llmFactory->getDefaultModel();
        $provider = $this->llmFactory->getDefaultProvider();

        /** @var string|null $customSpecPath */
        $customSpecPath = $input->getOption('spec');

        // Information utilisateur avant génération si la spec par défaut n'existe pas encore
        if (null === $customSpecPath && !$this->specManager->hasSpec($shortClassName)) {
            $expectedPath = $this->specManager->getSpecFilePath($shortClassName);
            $this->io->note(
                sprintf('Aucune spécification trouvée. Génération automatique dans : %s', $expectedPath)
            );
        }

        // Chargement ou génération de la spec
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

            if ($specContent) {
                $this->io->info('Une spécification métier a été injectée dans le contexte du LLM.');
            }
        } catch (\InvalidArgumentException $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $this->io->warning(
                sprintf('Impossible de générer ou charger la spec : %s. Poursuite sans spec.', $e->getMessage())
            );
            $specContent = null;
        }

        /** @var string|null $methodName */
        $methodName = $input->getOption('method');

        $this->io->title(
            sprintf('Analyse et génération de test %s pour : %s', $testType->label(), $shortClassName)
        );

        if ($methodName) {
            $this->io->text(sprintf('Cible spécifique : la méthode <info>%s()</info>', $methodName));
        }

        try {
            // On transmet $testType au Resolver pour adapter le dossier (Unit / Functional) et le namespace
            $testTargetPath = $this->pathResolver->resolve($fqcn, $shortClassName, $testType);

            if (!$input->getOption('method') && file_exists($testTargetPath->filePath)) {
                $this->io->warning(
                    'Un fichier de test existe déjà pour cette classe : ' . basename($testTargetPath->filePath)
                );

                $confirm = $this->io->confirm(
                    'Voulez-vous lancer la fusion automatique par le LLM sur ce fichier existant ?',
                    false
                );

                if (!$confirm) {
                    $this->io->note('Génération annulée pour préserver vos tests existants.');

                    return Command::SUCCESS;
                }
            }

            if (
                !is_dir($testTargetPath->targetDirectory)
                && !mkdir($testTargetPath->targetDirectory, 0777, true)
                && !is_dir($testTargetPath->targetDirectory)
            ) {
                throw new \RuntimeException(sprintf('Le dossier "%s" n\'a pas été créé', $testTargetPath->targetDirectory));
            }

            $existingTestCode = null;
            $testFileExisted = file_exists($testTargetPath->filePath);
            if ($testFileExisted) {
                if (Command::FAILURE === $this->checkTestFileIsClean($testTargetPath->filePath)) {
                    return Command::FAILURE;
                }

                $this->io->note('Un fichier de test existant a été détecté. Il va être transmis au LLM pour fusion.');
                $existingTestCode = file_get_contents($testTargetPath->filePath);
                $existingTestCode = $this->testGenerator->replaceDynamicHeadersInExistingTestCode(
                    existingTestCode: $existingTestCode,
                    targetNamespace: $testTargetPath->targetNamespace,
                    className: $shortClassName,
                );
            }

            $this->io->comment(sprintf('Envoi du code au LLM (%s)...', $model));

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

            file_put_contents($testTargetPath->filePath, $testCode);

            $this->displayFinalMessage($testFileExisted, $testTargetPath, $testType);

            $this->displayExecutionTime($startTime, $startMicrotime);

            return Command::SUCCESS;
        } catch (TestCorrectionException $e) {
            // Échec Métier : Le LLM n'a pas réussi à corriger le test après les tentatives configurées.
            $this->io->error('Échec de la génération automatique :');
            $this->io->writeln($e->getMessage());
            $this->displayExecutionTime($startTime, $startMicrotime);

            return Command::FAILURE;
        } catch (PhpStanNotFoundException $e) {
            // Prise en charge explicite du prérequis PHPStan
            $this->io->error($e->getMessage());
            $this->io->note('Pour activer la validation statique lors de la génération, exécutez :');
            $this->io->comment('composer require --dev phpstan/phpstan');
            $this->displayExecutionTime($startTime, $startMicrotime);

            return Command::FAILURE;
        } catch (\JsonException $e) {
            // Échec Technique : La sortie de PHPStan ou du client LLM n'est pas du JSON valide
            $this->io->error('Erreur d\'analyse de la réponse JSON (PHPStan ou LLM) : ' . $e->getMessage());
            $this->io->note('Vérifiez que PHPStan fonctionne correctement en exécutant manuellement la commande sur le projet.');
            $this->displayExecutionTime($startTime, $startMicrotime);

            return Command::FAILURE;
        } catch (\Exception $e) {
            // Toutes les autres exceptions inattendues
            $this->io->error('Une erreur inattendue est survenue : ' . $e->getMessage());
            $this->displayExecutionTime($startTime, $startMicrotime);

            return Command::FAILURE;
        }
    }

    private function checkTestFileIsClean(string $path): int
    {
        $checkClean = new Process(['git', 'status', '--porcelain', $path], $this->projectDir);
        $checkClean->run();
        $isDirty = !empty(trim($checkClean->getOutput()));

        if ($isDirty) {
            $this->io->warning('Le fichier de test existant a des modifications non versionnées dans Git.');
            if (!$this->io->confirm('Voulez-vous continuer et écraser ces modifications temporairement ?', false)) {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Affiche le message final.
     *
     * Si un fichier de test a été mis à jour par le LLM, on affiche une procédure de restauration.
     * Sinon, on indique simplement le chemin versv le fichier généré.
     */
    private function displayFinalMessage(bool $testFileExisted, TestTargetPath $testTargetPath, TestType $testType): void
    {
        $relativeLogPath = str_replace($this->normalizedProjectDir . '/', '', $testTargetPath->filePath);

        if ($testFileExisted) {
            $this->io->success('Le fichier de test existant a été mis à jour et fusionné par le LLM !');
            $this->io->success('Fichier généré dans : ' . $relativeLogPath);
            $this->io->section('Sécurité & Revue de code');
            $this->io->info(
                [
                    'Le code existant a été préservé et enrichi.',
                    "Utilisez votre IDE ou la commande 'git diff' pour inspecter les ajouts de l'IA.",
                    "Si le résultat ne vous convient pas, vous pouvez l'annuler à tout moment avec : ",
                    'git restore ' . $relativeLogPath,
                ]
            );

            return;
        }
        $this->io->success(
            sprintf(
                'Le fichier de test %s a été généré avec succès dans : %s',
                $testType->label(),
                $relativeLogPath
            )
        );
    }

    private function displayExecutionTime(\DateTimeImmutable $startTime, float $startMicrotime): void
    {
        $endTime = new \DateTimeImmutable();
        $durationInSeconds = round(microtime(true) - $startMicrotime, 2);

        if ($durationInSeconds >= 60) {
            $minutes = (int) ($durationInSeconds / 60);
            $seconds = round(fmod($durationInSeconds, 60), 1);
            $formattedDuration = sprintf('%dm %ss', $minutes, $seconds);
        } else {
            $formattedDuration = sprintf('%.2fs', $durationInSeconds);
        }

        $this->io->newLine();
        $this->io->definitionList(
            ['Début' => $startTime->format('H:i:s')],
            ['Fin' => $endTime->format('H:i:s')],
            ['Durée totale' => $formattedDuration]
        );
    }
}
