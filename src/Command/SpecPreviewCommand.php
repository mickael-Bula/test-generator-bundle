<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Command;

use Mika\TestGeneratorBundle\Manager\SpecManager;
use Mika\TestGeneratorBundle\Resolver\ClassResolver;
use Mika\TestGeneratorBundle\Spec\SpecSanitizer;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'llm:spec:preview',
    description: 'Affiche ce que le nettoyage de spec modifie (spec brute vers spec envoyée au LLM).',
)]
final class SpecPreviewCommand extends Command
{
    public function __construct(
        private readonly ClassResolver $classResolver,
        private readonly SpecManager $specManager,
        private readonly SpecSanitizer $sanitizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('class', InputArgument::REQUIRED, 'Classe testée (nom, chemin ou FQCN)')
            ->addOption('spec', 's', InputOption::VALUE_REQUIRED, 'Chemin d\'une spec précise');
    }

    /**
     * @throws \ReflectionException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $resolved = $this->classResolver->resolve((string) $input->getArgument('class'));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $short = basename(str_replace('\\', '/', $resolved->className));
        $path = $input->getOption('spec') ?? $this->specManager->getSpecFilePath($short);
        if (!is_file($path)) {
            $io->error(sprintf('Spec introuvable : %s', $path));

            return Command::FAILURE;
        }

        $raw = (string) file_get_contents($path);
        $clean = $this->sanitizer->sanitize($raw, $resolved->className);

        $diff = (new Differ(new UnifiedDiffOutputBuilder("--- spec brute\n+++ spec envoyée au LLM\n")))->diff($raw, $clean);
        $output->writeln('' === trim($diff) ? '(aucune modification)' : $diff, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}