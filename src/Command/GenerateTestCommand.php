<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command;

use Mika\TestGeneratorBundle\Enum\TestType;
use Mika\TestGeneratorBundle\Exception\TestCorrectionException;
use Mika\TestGeneratorBundle\Llm\LlmClientFactory;
use Mika\TestGeneratorBundle\Resolver\ClassResolver;
use Mika\TestGeneratorBundle\Resolver\SpecResolver;
use Mika\TestGeneratorBundle\Resolver\TestPathResolver;
use Mika\TestGeneratorBundle\Service\TestGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:generate-test',
    description: 'Génère un test unitaire PHPUnit pour une classe donnée via le LLM configuré, avec validation automatique.',
)]
class GenerateTestCommand extends Command
{
    private SymfonyStyle $io;

    public function __construct(
        private readonly TestGenerator $testGenerator,
        private readonly LlmClientFactory $llmFactory, // Injecte la factory qui récupère le client et le modèle.
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly ClassResolver $classResolver,
        private readonly SpecResolver $specResolver,
        private readonly TestPathResolver $pathResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'class',
            InputArgument::REQUIRED,
            'Le nom de la classe à tester, '
                . 'ou son chemin (ex: src/Service/CalculatorService.php) '
                . 'ou encore son namespace (ex : \\App\\Service\\Calculator)'
        )->addOption(
            'method',
            'm',
            InputOption::VALUE_REQUIRED,
            'Cibler une méthode spécifique de la classe à tester'
        )->addOption(
            'model',
            null,
            InputOption::VALUE_OPTIONAL,
            'Modèle LLM spécifique à utiliser (ex: qwen2.5-coder:14b ou un modèle OpenRouter)',
        )
        ->addOption(
            'spec',
            's',
            InputOption::VALUE_OPTIONAL,
            'Fichier de spécification (.md), texte libre ou convention automatique '
                . '(<SpecDir>/<ClassName>Spec.md) si aucun argument n\'est fourni.',
            false // Valeur par défaut quand l'option --spec n'est pas présente dasn la commande
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
        // Initialisation du chronomètre et de l'horodatage de début
        $timezone = new \DateTimeZone(date_default_timezone_get());
        $startTime = new \DateTimeImmutable('now', $timezone);
        $startMicrotime = microtime(true);

        $this->io = new SymfonyStyle($input, $output);

        // Affichage de l'heure de début dès le lancement
        $this->io->text(sprintf('⏱️  Début d\'exécution : <info>%s</info>', $startTime->format('H:i:s')));

        $targetInput = $input->getArgument('class');

        // 1. Vérification de la présence du binaire PHPUnit
        $phpunitBinary = $this->projectDir
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'phpunit';

        if (!file_exists($phpunitBinary) && !file_exists($phpunitBinary . '.bat')) {
            $this->io->error('PHPUnit n\'est pas installé sur le projet hôte.');
            $this->io->note('Exécutez : composer require --dev phpunit/phpunit');

            return Command::FAILURE;
        }

        // 2. Vérification de la présence du fichier de configuration de PHPUnit
        $configFiles = ['phpunit.xml', 'phpunit.xml.dist', 'phpunit.dist.xml'];

        $hasConfig = (bool) array_filter(
            $configFiles,
            fn ($file) => file_exists($this->projectDir . DIRECTORY_SEPARATOR . $file)
        );

        if (!$hasConfig) {
            // Formate les premiers éléments séparés par des virgules et le dernier par "ou".
            $lastFile = array_pop($configFiles);
            $formattedList = implode(', ', $configFiles) . ' ou ' . $lastFile;

            $this->io->error(sprintf(
                'Aucun fichier de configuration PHPUnit (%s) n\'a été trouvé à la racine du projet.',
                $formattedList)
            );
            $this->io->note('Vous pouvez en générer un en exécutant : composer require --dev symfony/test-pack');

            return Command::FAILURE;
        }

        try {
            // Traitement du type de test (unitaire ou fonctionnel)
            $isUnit = (bool) $input->getOption('unit');
            $isFunctional = (bool) $input->getOption('functional');

            // Validation pour empêcher d'activer les deux flags en même temps
            if ($isUnit && $isFunctional) {
                $this->io->error('Vous ne pouvez pas spécifier à la fois --unit (-u) et --functional (-f).');

                return Command::FAILURE;
            }

            // Détermination du type (par défaut : UNIT).
            $testType = $isFunctional ? TestType::FUNCTIONAL : TestType::UNIT;

            // Résolution automatique de l'entrée
            $resolved = $this->classResolver->resolve($targetInput);

            $fqcn = $resolved['className'];
            $filePath = $resolved['filePath'];

            // Si le fichier n'existe pas, on arrête l'exécution de la commande.
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

        // On extrait le nom court de la classe (ex : "CalculatorService") depuis le FQCN
        $shortClassName = basename(str_replace('\\', '/', $fqcn));

        // Récupère le modèle passé en option, sinon celui déclaré par défaut dans les variables d'environnement
        $model = $input->getOption('model') ?? $this->llmFactory->getDefaultModel();

        // Récupère le provider
        $provider = $this->llmFactory->getDefaultProvider();

        // Récupération du contenu de la spécification
        $specOption = $input->getOption('spec');

        // Résolution du contenu de la spécification
        $specContent = $this->specResolver->resolve($specOption, $shortClassName, $this->io);

        /** @var string|null $methodName */
        $methodName = $input->getOption('method');

        $this->io->title(sprintf('Analyse et génération de test %s pour : %s', $testType->label(), $shortClassName));

        if ($methodName) {
            $this->io->text(sprintf('Cible spécifique : la méthode <info>%s()</info>', $methodName));
        }

        try {
            [$targetNamespace, $finalDisplayDir, $finalAbsoluteFilePath] = $this->pathResolver->resolve($fqcn, $shortClassName);

            if (!$input->getOption('method') && file_exists($finalAbsoluteFilePath)) {
                $this->io->warning('Un fichier de test existe déjà pour cette classe : ' . basename($finalAbsoluteFilePath));

                $confirm = $this->io->confirm(
                    'Voulez-vous lancer la fusion automatique par le LLM sur ce fichier existant ?',
                    false
                );

                if (!$confirm) {
                    $this->io->note('Génération annulée pour préserver vos tests existants.');

                    return Command::SUCCESS;
                }
            }

            if (!is_dir($finalDisplayDir) && !mkdir($finalDisplayDir, 0777, true) && !is_dir($finalDisplayDir)) {
                throw new \RuntimeException(sprintf('Le dossier "%s" n\'a pas été créé', $finalDisplayDir));
            }

            $existingTestCode = null;
            $testFileExisted = file_exists($finalAbsoluteFilePath);
            if ($testFileExisted) {
                if (Command::FAILURE === $this->checkTestFileIsClean($finalAbsoluteFilePath)) {
                    return Command::FAILURE;
                }

                $this->io->note('Un fichier de test existant a été détecté. Il va être transmis au LLM pour fusion.');
                $existingTestCode = file_get_contents($finalAbsoluteFilePath);
                $existingTestCode = $this->testGenerator->replaceDynamicHeadersInExistingTestCode(
                    existingTestCode: $existingTestCode,
                    targetNamespace: $targetNamespace,
                    className: $shortClassName,
                );
            }

            $this->io->comment(sprintf('Envoi du code au LLM (%s)...', $model));

            if ($specContent) {
                $this->io->info('Une spécification métier a été injectée dans le contexte du LLM.');
            }

            // Appel du LLM
            try {
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
            } catch (\RuntimeException|TestCorrectionException $e) {
                // Intercepte les erreurs de Repo-Map ainsi que l'échec de correction PHPUnit
                $this->io->error($e->getMessage());

                return Command::FAILURE;
            }

            $testCode = $this->testGenerator->replaceDynamicHeadersInTestCode(
                testCode: $testCode,
                targetNamespace: $targetNamespace,
                className: $shortClassName,
            );

            file_put_contents($finalAbsoluteFilePath, $testCode);

            if ($testFileExisted) {
                $this->io->success('Le fichier de test existant a été mis à jour et fusionné par le LLM !');
                $this->io->section('🔍 Sécurité & Revue de code');
                $this->io->info([
                    'Le code existant a été préservé et enrichi.',
                    "Utilisez votre IDE ou la commande 'git diff' pour inspecter les ajouts de l'IA.",
                    "Si le résultat ne vous convient pas, vous pouvez l'annuler à tout moment avec :",
                    '👉 git restore ' . str_replace($this->projectDir . '/', '', $finalAbsoluteFilePath),
                ]);
            } else {
                $relativeLogPath = str_replace($this->projectDir . '/', '', $finalAbsoluteFilePath);
                $this->io->success(
                    sprintf(
                        'Le fichier de test %s a été généré avec succès dans : %s',
                        $testType->label(),
                        $relativeLogPath
                    )
                );
            }

            // Affichage de l'heure de fin à l'arrêt de la commande
            $this->displayExecutionTime($startTime, $startMicrotime);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->io->error('Une erreur est survenue lors de la génération : ' . $e->getMessage());

            // Affichage de l'heure de fin à l'arrêt de la commande
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

    private function displayExecutionTime(\DateTimeImmutable $startTime, float $startMicrotime): void
    {
        $endTime = new \DateTimeImmutable();
        $durationInSeconds = round(microtime(true) - $startMicrotime, 2);

        // Formate la durée (ex: "45.2s" ou "2m 15s")
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
