<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Resolver;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class FixerSkillResolver
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/skills/fixer')] private string $skillsDir,
    ) {
    }

    /**
     * Analyse la sortie de PHPUnit et retourne les contenus des Skills applicables.
     */
    public function resolveFromPhpUnitOutput(string $phpUnitOutput): string
    {
        $skillsToLoad = [];

        // Détection de ClassIsFinalException
        if (
            str_contains($phpUnitOutput, 'ClassIsFinalException')
            || str_contains($phpUnitOutput, 'is declared "final" and cannot be doubled')
        ) {
            $skillsToLoad[] = 'class_is_final.md';
        }

        // Détection de IncompatibleReturnValueException / void return
        if (
            str_contains($phpUnitOutput, 'may not return value of type')
            || str_contains($phpUnitOutput, 'its declared return type is \'void\'')
        ) {
            $skillsToLoad[] = 'void_return.md';
        }

        // Détection des erreurs de système de fichiers / chemins
        if (
            str_contains($phpUnitOutput, 'IOException')
            || str_contains($phpUnitOutput, 'Failed asserting that false is true')
        ) {
            $skillsToLoad[] = 'filepath_and_assertions.md';
        }

        // Détection ciblée de ReflectionMethod / ReflectionProperty::setAccessible()
        if (
            str_contains($phpUnitOutput, 'setAccessible')
            || str_contains($phpUnitOutput, 'ReflectionMethod::setAccessible')
            || str_contains($phpUnitOutput, 'ReflectionProperty::setAccessible')
        ) {
            $skillsToLoad[] = 'reflection_php81.md';
        }

        $loadedSkills = [];
        foreach ($skillsToLoad as $file) {
            $path = $this->skillsDir . '/' . $file;
            if (file_exists($path)) {
                $loadedSkills[] = file_get_contents($path);
            }
        }

        return implode("\n\n---\n\n", $loadedSkills);
    }
}
