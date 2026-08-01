<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Resolver;

use Symfony\Component\Console\Command\Command;

class SkillResolver
{
    private string $nativeSkillsDir;

    public function __construct(
        private readonly ?string $customSkillsDir = null,
        ?string $nativeSkillsDir = null,
    ) {
        // Chemin relatif vers src/Resources/skills
        $this->nativeSkillsDir = $nativeSkillsDir ?? \dirname(__DIR__).'/Resources/skills';
    }

    /**
     * Résolution et concatènation des skills pertinents selon la classe à tester.
     * La méthode s'appuie sur l'autoloader de Composer, mais conserve une détection de secours sur le code source :
     * si la classe n'a pas éncore été chargée par Composer,
     * on tente de lire les informations directement depuis le code source.
     */
    public function resolveForClass(string $fqcn, string $classCode): string
    {
        $skillsToLoad = [];

        // 1. Skill : Commandes Symfony
        if ($this->isSymfonyCommand($fqcn, $classCode)) {
            $skillsToLoad[] = 'symfony_command.md';
        }

        // 2. Skill : Système de fichiers / I/O
        if ($this->hasFilesystemOperations($classCode)) {
            $skillsToLoad[] = 'filesystem_test.md';
        }

        // 3. Skill : PhpParser v5
        if ($this->usesPhpParser($classCode)) {
            $skillsToLoad[] = 'php-parser-v5.md';
        }

        // Chargement du contenu (on s'assure de ne charger un skill qu'une seule fois).
        $loadedSkills = [];
        foreach (array_unique($skillsToLoad) as $skillFile) {
            $content = $this->loadSkill($skillFile);
            if (null !== $content) {
                $loadedSkills[] = $content;
            }
        }

        // 4. Ajout des skills personnalisés du projet
        $customSkills = $this->loadCustomSkills();

        return implode("\n\n", array_merge($loadedSkills, $customSkills));
    }

    private function isSymfonyCommand(string $fqcn, string $classCode): bool
    {
        return (class_exists($fqcn) && is_subclass_of($fqcn, Command::class))
            || 1 === preg_match('/\bextends\s+Command\b/', $classCode)
            || str_contains($classCode, '#[AsCommand');
    }

    private function hasFilesystemOperations(string $classCode): bool
    {
        // Utilisation de \b pour éviter de matcher des mots comme "UserFinder" ou "MyFilesystem"
        return 1 === preg_match('/\b(file_put_contents|file_get_contents|mkdir|sys_get_temp_dir|unlink)\b/', $classCode)
            || 1 === preg_match('/\buse\s+Symfony\\\\Component\\\\Finder\\\\Finder\b/', $classCode)
            || 1 === preg_match('/\buse\s+Symfony\\\\Component\\\\Filesystem\\\\Filesystem\b/', $classCode);
    }

    private function usesPhpParser(string $classCode): bool
    {
        return str_contains($classCode, 'PhpParser\\')
            || 1 === preg_match('/\buse\s+PhpParser\b/', $classCode);
    }

    private function loadSkill(string $filename): ?string
    {
        $path = $this->nativeSkillsDir.'/'.$filename;

        if (!file_exists($path)) {
            return null;
        }

        return file_get_contents($path) ?: null;
    }

    /**
     * @return array<string>
     */
    private function loadCustomSkills(): array
    {
        if (null === $this->customSkillsDir || !is_dir($this->customSkillsDir)) {
            return [];
        }

        $custom = [];
        foreach (glob($this->customSkillsDir.'/*.md') ?: [] as $file) {
            $custom[] = file_get_contents($file) ?: '';
        }

        return $custom;
    }
}
