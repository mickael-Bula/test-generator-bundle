<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Resolver;

final readonly class FixerSkillResolver
{
    private string $fixerSkillsDir;

    public function __construct()
    {
        // Remonte depuis src/Resolver jusqu'à la racine du bundle (dans vendor/mika/test-generator-bundle ou symlink)
        $this->fixerSkillsDir = \dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR . 'Resources'
            . DIRECTORY_SEPARATOR . 'skills'
            . DIRECTORY_SEPARATOR . 'fixer';
    }

    /**
     * Analyse la sortie d'erreur (PHPUnit ou PHPStan) et retourne les contenus des Skills applicables.
     */
    public function resolveFromErrorOutput(string $errorOutput): string
    {
        $skillsToLoad = [];

        // Détection des erreurs PHPStan (Niveau 6)
        if (
            str_contains($errorOutput, 'unresolvable native type')
            || str_contains($errorOutput, 'contains unresolvable type')
            || str_contains($errorOutput, 'has no value type specified in iterable type array')
            || str_contains($errorOutput, 'PHPStan')
        ) {
            $skillsToLoad[] = 'phpstan_level_6.md';
        }

        // Détection de ClassIsFinalException
        if (
            str_contains($errorOutput, 'ClassIsFinalException')
            || str_contains($errorOutput, 'is declared "final" and cannot be doubled')
        ) {
            $skillsToLoad[] = 'class_is_final.md';
        }

        // Détection de IncompatibleReturnValueException / void return
        if (
            str_contains($errorOutput, 'may not return value of type')
            || str_contains($errorOutput, 'its declared return type is \'void\'')
        ) {
            $skillsToLoad[] = 'void_return.md';
        }

        // Détection des erreurs de système de fichiers / chemins
        if (
            str_contains($errorOutput, 'IOException')
            || str_contains($errorOutput, 'Failed asserting that false is true')
        ) {
            $skillsToLoad[] = 'filepath_and_assertions.md';
        }

        // Détection ciblée de ReflectionMethod / ReflectionProperty::setAccessible()
        if (
            str_contains($errorOutput, 'setAccessible')
            || str_contains($errorOutput, 'ReflectionMethod::setAccessible')
            || str_contains($errorOutput, 'ReflectionProperty::setAccessible')
        ) {
            $skillsToLoad[] = 'reflection_php81.md';
        }

        $loadedSkills = [];
        foreach ($skillsToLoad as $file) {
            $path = $this->fixerSkillsDir . DIRECTORY_SEPARATOR . $file;
            if (file_exists($path)) {
                $loadedSkills[] = file_get_contents($path);
            }
        }

        return implode("\n\n---\n\n", $loadedSkills);
    }
}
