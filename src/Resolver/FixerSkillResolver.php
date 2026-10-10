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
            || str_contains($errorOutput, 'Call to an undefined method')
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

        // Détection des appels de méthodes inexistantes sur les DTOs / chaînages fluides interdits
        if (
            str_contains($errorOutput, 'Call to undefined method')
            && (str_contains($errorOutput, 'Dto') || str_contains($errorOutput, 'with'))
        ) {
            $skillsToLoad[] = 'dto_immutability.md';
        }

        // Détection des TypeError liés à des valeurs nulles (souvent des entités mal hydratées)
        if (
            str_contains($errorOutput, 'must be of type')
            && str_contains($errorOutput, 'null given')
        ) {
            $skillsToLoad[] = 'entity_missing_setters.md';
        }

        // Détection des erreurs de cycle de vie PHPUnit (ex : expects() sur un mock non initialisé ou sur une méthode personnalisée)
        if (
            str_contains($errorOutput, 'Call to a member function expects() on null')
            || str_contains($errorOutput, 'setUpDependencies')
        ) {
            $skillsToLoad[] = 'phpunit_lifecycle.md';
        }

        // Détection des erreurs de types inconnus / classes ou interfaces non trouvées (UnknownTypeException)
        if (
            str_contains($errorOutput, 'UnknownTypeException')
            || (str_contains($errorOutput, 'Class or interface') && str_contains($errorOutput, 'does not exist'))
        ) {
            $skillsToLoad[] = 'unknown_type_exception.md';
        }

        // Détection des erreurs d'arguments sur le constructeur (souvent causées par une ré-instanciation dans les tests)
        if (
            str_contains($errorOutput, 'ArgumentCountError')
            && str_contains($errorOutput, '__construct()')
        ) {
            $skillsToLoad[] = 'sut_reinstantiation.md';
        }

        // Détection des erreurs de type sur les assignations de propriétés ou types non qualifiés
        if (
            str_contains($errorOutput, 'TypeError')
            && str_contains($errorOutput, 'Cannot assign')
        ) {
            $skillsToLoad[] = 'unknown_type_exception.md';
        }

        // Détection des erreurs de variables nulles issues d'une mauvaise utilisation du setUp()
        if (
            str_contains($errorOutput, 'must be of type')
            || str_contains($errorOutput, 'Call to a member function') && str_contains($errorOutput, 'on null')
        ) {
            $skillsToLoad[] = 'test_fixture_scope.md';
        }

        // Détection des erreurs de syntaxe PHP liées à des attributs mal placés ou des jetons inattendus
        if (
            str_contains($errorOutput, 'Syntax error')
            && (str_contains($errorOutput, 'T_VARIABLE') || str_contains($errorOutput, 'attribute'))
        ) {
            $skillsToLoad[] = 'php_attributes_instantiation.md';
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
