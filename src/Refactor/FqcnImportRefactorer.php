<?php

declare (strict_types = 1);

namespace Mika\TestGeneratorBundle\Refactor;

final class FqcnImportRefactorer
{
    /**
     * Post-processeur automatique : convertit les FQCN inline (App\, Doctrine\, PHPUnit\)
     * en instructions 'use' propres en début de fichier et raccourcit les types.
     */
    public function refactor(string $phpCode): string
    {
        // 1. Capture tous les FQCN cibles (App\, Doctrine\, PHPUnit\MockObject)
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

            // Génère l'instruction use (ex : use App\Entity\MaClasse;)
            $useStatements[] = "use " . ltrim($fqcn, '\\') . ";";

            // Prépare le dictionnaire de remplacement (FQCN -> Nom court)
            $replacements[$fqcn] = $shortName;
        }

        // Supprime les doublons d'imports et trie par ordre alphabétique
        $useStatements = array_unique($useStatements);
        sort($useStatements);
        $useBlock = implode("\n", $useStatements);

        // Trie les remplacements du plus long au plus court pour éviter les conflits de sous-chaînes
        uksort($replacements, fn($a, $b) => strlen($b) - strlen($a));

        // Remplace les FQCN dans le corps du code par leur nom court
        foreach ($replacements as $fqcn => $shortName) {
            $phpCode = str_replace($fqcn, $shortName, $phpCode);
        }

        // Insère le bloc de 'use' juste après la déclaration du namespace
        if (preg_match('/namespace\s+[^;]+;\s*/', $phpCode, $nsMatches)) {
            $phpCode = str_replace($nsMatches[0], $nsMatches[0] . "\n" . $useBlock . "\n", $phpCode);
        }

        return $phpCode;
    }
}
