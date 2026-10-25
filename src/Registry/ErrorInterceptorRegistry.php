<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Registry;

use Mika\TestGeneratorBundle\Interceptor\ErrorInterceptorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ErrorInterceptorRegistry
{
    /** @param iterable<ErrorInterceptorInterface> $interceptors */
    public function __construct(
        private iterable $interceptors,
        private int $maxChars = 10000,
        private ?LoggerInterface $logger = null,
        #[Autowire('%kernel.project_dir%')] private string $projectDir = '',
    ) {
    }

    /**
     * État de chaque intercepteur enregistré : sert au diagnostic ("pourquoi mon indice n'apparaît pas ?").
     *
     * @return list<array{interceptor: string, supports: bool, hint: ?string, error: ?string}>
     */
    public function inspect(string $errorOutput): array
    {
        if ('' !== $this->projectDir) {
            $errorOutput = str_replace('%PROJECT_DIR%', $this->projectDir, $errorOutput);
        }

        $rows = [];
        foreach ($this->interceptors as $interceptor) {
            $row = [
                'interceptor' => $interceptor::class,
                'supports' => false,
                'hint' => null,
                'error' => null
            ];

            try {
                $row['supports'] = $interceptor->supports($errorOutput);
                if ($row['supports']) {
                    $hint = $interceptor->intercept($errorOutput);
                    $row['hint'] = null !== $hint && '' !== trim($hint) ? rtrim($hint) : null;
                }
            } catch (\Throwable $e) {
                // Un intercepteur défaillant ne doit ni masquer les autres ni interrompre la boucle de correction
                $row['error'] = $e::class . ' : ' . $e->getMessage();
                $this->logger?->warning(sprintf('Intercepteur %s en erreur : %s', $interceptor::class, $e->getMessage()));
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public function resolveFromErrorOutput(string $errorOutput): ?string
    {
        $kept = [];
        $omitted = [];
        $length = 0;

        foreach ($this->inspect($errorOutput) as $row) {
            if (null === $row['hint']) {
                continue;
            }
            $size = \strlen($row['hint']);
            if ([] !== $kept && $length + $size > $this->maxChars) {
                $omitted[] = (string) strtok($row['hint'], "\n");
                continue;
            }
            $kept[] = $row['hint'];
            $length += $size;
        }

        if ([] === $kept) {
            return null;
        }
        if ([] !== $omitted) {
            $kept[] = '(Indices omis faute de place : ' . implode(' ; ', $omitted) . ')';
        }

        return implode("\n\n", $kept);
    }
}