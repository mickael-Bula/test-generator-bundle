<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Refactor;

final class FqcnImportRefactorer
{
    /**
     * Post-processeur automatique : convertit les FQCN inline (App\, Doctrine\, PHPUnit\MockObject)
     * en instructions 'use' propres en début de fichier et raccourcit les types.
     * Un nom court qui a déjà été importé ne l'est jamais une seconde fois, et un nom court
     * pris par une AUTRE classe laisse le FQCN en ligne.
     */
    public function refactor(string $phpCode): string
    {
        // 1. Capture tous les FQCN cibles (App\, Doctrine\, PHPUnit\MockObject)
        preg_match_all('/\\\\(App|Doctrine|PHPUnit\\\\Framework\\\\MockObject)\\\\[a-zA-Z0-9_\\\\]+/', $phpCode, $matches);
        $fqcns = array_unique($matches[0]);

        if ([] === $fqcns) {
            return $phpCode;
        }

        // Noms courts déjà pris par un import existant : nom court => FQCN (en minuscules)
        $taken = $this->existingImports($phpCode);

        $useStatements = [];
        $replacements = [];

        foreach ($fqcns as $fqcn) {
            $name = ltrim($fqcn, '\\');
            $parts = explode('\\', $name);
            $shortName = end($parts);
            $key = strtolower($shortName);

            if (isset($taken[$key])) {
                // Même classe déjà importée : on raccourcit sans ajouter de `use`.
                // Autre classe sous le même nom court : le FQCN reste en ligne.
                if ($taken[$key] === strtolower($name)) {
                    $replacements[$fqcn] = $shortName;
                }

                continue;
            }

            $taken[$key] = strtolower($name);
            $useStatements[] = 'use ' . $name . ';';
            $replacements[$fqcn] = $shortName;
        }

        // Si aucun `use` n'est généré, inutile d'aller plus loin
        if ([] === $useStatements) {
            return $phpCode;
        }

        // Sans namespace, il n'y a nulle part où insérer les `use` : on ne touche à rien.
        if (1 !== preg_match('/namespace\s+[^;]+;\s*/', $phpCode, $nsMatches)) {
            return $phpCode;
        }

        // Remplace les FQCN par leur nom court, sans entamer un FQCN plus long qui les contient
        foreach ($replacements as $fqcn => $shortName) {
            $phpCode = preg_replace(
                '/' . preg_quote($fqcn, '/') . '(?![A-Za-z0-9_\\\\])/',
                $shortName,
                $phpCode,
            ) ?? $phpCode;
        }

        $useStatements = array_unique($useStatements);
        sort($useStatements);
        $useBlock = implode("\n", $useStatements);

        // Insère le bloc de 'use' juste après la déclaration du namespace
        return str_replace($nsMatches[0], $nsMatches[0] . "\n" . $useBlock . "\n", $phpCode);
    }

    /**
     * Imports de premier niveau déjà présents (`use A\B;` et `use A\B as C;`).
     *
     * @return array<string, string> nom court (minuscules) => FQCN (minuscules, sans antislash initial)
     */
    private function existingImports(string $phpCode): array
    {
        preg_match_all(
            '/^[ \t]*use\s+(?!function\b|const\b)\\\\?([\w\\\\]+?)(?:\s+as\s+(\w+))?\s*;/mi',
            $phpCode,
            $matches,
            PREG_SET_ORDER,
        );

        $imports = [];
        foreach ($matches as $match) {
            $parts = explode('\\', $match[1]);
            $short = '' !== ($match[2] ?? '') ? $match[2] : end($parts);
            $imports[strtolower($short)] = strtolower($match[1]);
        }

        return $imports;
    }
}
