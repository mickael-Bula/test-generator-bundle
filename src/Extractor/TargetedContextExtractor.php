<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Extractor;

final class TargetedContextExtractor
{
    public function extractContext(string $filePath, string$fqcn): string
    {
        if (!class_exists($fqcn)) {
            return "Classe introuvable pour extraction.";
        }

        $reflection = new \ReflectionClass($fqcn);
        $methodsInfo = [];
        $dependencies = [];
        $relatedBlueprints = [];

        // 1. Analyse du constructeur (dépendances à mocker)
        if ($reflection->hasMethod('__construct')) {
            $constructor = $reflection->getMethod('__construct');
            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();
                $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';
                $dependencies[] = sprintf('- Dépendance (à mocker) : \%s $%s', $typeName, $param->getName());
            }
        }

        // 2. Analyse des méthodes publiques ET extraction des blueprints des DTOs/Objets associés
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->isDestructor()) {
                continue;
            }

            $returnType = $method->getReturnType();
            $returnTypeStr = $returnType instanceof \ReflectionNamedType ? $returnType->getName() : 'mixed';
            $params = [];

            foreach ($method->getParameters() as $param) {
                $pType = $param->getType();
                $pTypeName = $pType instanceof \ReflectionNamedType ? $pType->getName() : 'mixed';
                $params[] = sprintf('\%s$%s', $pTypeName, $param->getName());

                // Si le paramètre est une classe de l'application (ex : DTO, Entité), on extrait son blueprint
                if ($pType instanceof \ReflectionNamedType && !$pType->isBuiltin()) {
                    $paramClassName = $pType->getName();
                    if (class_exists($paramClassName) && str_starts_with($paramClassName, 'App\\')) {
                        $relatedBlueprints[$paramClassName] = $this->extractClassBlueprint($paramClassName);
                    }
                }
            }

            $methodsInfo[] = sprintf(
                '- public function \%s(\%s): \%s',
                $method->getName(),
                implode(', ', $params),
                $returnTypeStr
            );
        }

        $blueprintSection = '';
        if (!empty($relatedBlueprints)) {
            $blueprintSection = "\n=== BLUEPRINTS DES OBJETS / DTOS UTILISÉS EN PARAMÈTRES ===\n"
            . implode("\n\n", $relatedBlueprints) . "\n";
        }

        return sprintf(
            "=== CONTEXTE CIBLÉ DE LA CLASSE ===\n"
            . "Classe : %s\n"
            . "Fichier : %s\n\n"
            . "Dépendances du constructeur (à mocker) :\n%s\n\n"
            . "Méthodes publiques disponibles :\n%s\n"
            . "%s",
            $fqcn,
            $filePath,
            !empty($dependencies) ? implode("\n", $dependencies) : "Aucune dépendance",
            implode("\n", $methodsInfo),
            $blueprintSection
        );
    }

    /**
     * Extrait le constructeur et les méthodes d'un DTO ou d'une Entité pour que le LLM sache les instancier.
     */
    private function extractClassBlueprint(string $fqcn): string
    {
        try {
            $reflection = new \ReflectionClass($fqcn);
            $constructor = $reflection->getConstructor();
            $params = [];

            if ($constructor) {
                foreach ($constructor->getParameters() as $param) {
                    $pType = $param->getType();
                    $pTypeName = $pType instanceof \ReflectionNamedType ? $pType->getName() : 'mixed';
                    $params[] = sprintf(
                        '%s $\%s\%s',
                        $pTypeName,
                        $param->getName(),
                        $param->isOptional() ? ' [optionnel (défaut)]' : ' [REQUIS]'
                    );
                }
            }

            return sprintf(
                "Objet : %s\n"
                . "-> Constructeur : `new \\%s(%s)`",
                $fqcn,
                $fqcn,
                !empty($params) ? implode(', ', $params) : 'Aucun argument'
            );
        } catch (\Exception $e) {
            return "Impossible d'extraire le blueprint pour $fqcn";
        }
    }
}