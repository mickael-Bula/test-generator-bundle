<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Resolver;

use Mika\TestGeneratorBundle\Dto\ResolvedClass;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

readonly class ClassResolver
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {
    }

    /**
     * Résout l'entrée utilisateur (chemin Windows/Linux, FQCN, ou nom court)
     * pour retourner le FQCN exact et le chemin absolu du fichier.
     *
     * @throws \InvalidArgumentException Si la classe ne peut pas être résolue ou en cas d'ambigüité
     */
    public function resolve(string $input): ResolvedClass
    {
        $input = trim($input);

        if ('' === $input) {
            throw new \InvalidArgumentException('L\'argument de classe ne peut pas être vide.');
        }

        // On supprime le suffixe ::class si présent (ex : "VatCalculator::class" → "VatCalculator").
        $cleanedInput = preg_replace('/::class$/i', '', $input);
        if (is_string($cleanedInput)) {
            $input = $cleanedInput;
        }

        // Normalisation des doubles antislashes (App\\Service -> App\Service)
        $cleanedInput = preg_replace('/\\\\{2,}/', '\\', $input);
        if (is_string($cleanedInput)) {
            $input = $cleanedInput;
        }

        $normalizedPathInput = str_replace('\\', '/', $input);

        // Tentative par les trois stratégies successives : si null est retourné, on passe à la suivante.
        $result = $this->resolveByFilePath($input, $normalizedPathInput)
            ?? $this->resolveByFqcn($input)
            ?? $this->resolveByShortName($normalizedPathInput);

        if (null !== $result) {
            return $result;
        }

        $message = sprintf('Impossible de résoudre la classe ou le fichier à partir de la saisie "%s".', $input);
        throw new \InvalidArgumentException($message);
    }

    /**
     * Cas 1 : Chemin de fichier (ex : "src/Service/VatCalculator.php").
     */
    private function resolveByFilePath(string $input, string $normalizedPath): ?ResolvedClass
    {
        $realPath = realpath($input);

        if (!$realPath && !str_starts_with($normalizedPath, '/')) {
            $realPath = realpath($this->projectDir . DIRECTORY_SEPARATOR . $normalizedPath);
        }

        if ($realPath && is_file($realPath)) {
            return new ResolvedClass(
                className: $this->extractFqcnFromFile($realPath),
                filePath: $realPath,
            );
        }

        return null;
    }

    /**
     * Cas 2 : Namespace / FQCN complet (ex : "App\Service\VatCalculator").
     */
    private function resolveByFqcn(string $input): ?ResolvedClass
    {
        $fqcnInput = ltrim(str_replace('/', '\\', $input), '\\');

        if (class_exists($fqcnInput) || interface_exists($fqcnInput) || trait_exists($fqcnInput)) {
            $reflection = new \ReflectionClass($fqcnInput);
            $fileName = $reflection->getFileName();

            if ($fileName && is_file($fileName)) {
                return new ResolvedClass(
                    className: $reflection->getName(),
                    filePath: $fileName,
                );
            }
        }

        return null;
    }

    /**
     * Cas 3 : Nom court de classe (ex : "VatCalculator").
     */
    private function resolveByShortName(string $normalizedPath): ?ResolvedClass
    {
        $shortName = basename($normalizedPath);
        $shortName = preg_replace('/\.php$/i', '', $shortName) ?? $shortName;

        $matches = $this->findClassInProject($shortName);

        if (1 === \count($matches)) {
            return $matches[0];
        }

        if (\count($matches) > 1) {
            $foundFqcn = array_map(static fn (ResolvedClass $resolved) => $resolved->className, $matches);

            $message = sprintf(
                'Ambigüité : plusieurs classes correspondent au nom "%s" : %s. '
                    . 'Veuillez préciser le namespace complet ou le chemin du fichier.',
                $shortName,
                implode(', ', $foundFqcn)
            );
            throw new \InvalidArgumentException($message);
        }

        return null;
    }

    /**
     * Recherche une classe par son nom court dans le dossier src/.
     *
     * @return array<int, ResolvedClass>
     */
    private function findClassInProject(string $shortClassName): array
    {
        $srcDir = $this->projectDir . DIRECTORY_SEPARATOR . 'src';
        if (!is_dir($srcDir)) {
            return [];
        }

        $finder = new Finder();
        $finder->files()->in($srcDir)->name($shortClassName . '.php');

        // Pour éviter de recalculer la concaténation '\\'.$shortClassName, on l'enregistre hors de la boucle.
        $suffix = '\\' . $shortClassName;

        $results = [];
        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            if (false === $filePath) {
                continue;
            }

            $fqcn = $this->extractFqcnFromFile($filePath);

            if ($fqcn === $shortClassName || str_ends_with($fqcn, $suffix)) {
                $results[] = new ResolvedClass(
                    className: $fqcn,
                    filePath: $filePath,
                );
            }
        }

        return $results;
    }

    /**
     * Extrait le FQCN (Namespace + Nom de classe) d'un fichier PHP sans l'exécuter.
     */
    private function extractFqcnFromFile(string $filePath): string
    {
        $content = file_get_contents($filePath);
        if (false === $content) {
            throw new \InvalidArgumentException("Impossible de lire le fichier : $filePath");
        }

        $namespace = '';
        $class = '';

        if (preg_match('/namespace\s+([^;]+);/i', $content, $matches)) {
            $namespace = trim($matches[1]);
        }

        if (preg_match('/(?:class|interface|trait|enum)\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)/i', $content,
            $matches)) {
            $class = trim($matches[1]);
        }

        return $namespace ? $namespace . '\\' . $class : $class;
    }
}
