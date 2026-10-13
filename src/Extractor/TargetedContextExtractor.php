<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Extractor;

final class TargetedContextExtractor
{
    public function extractContext(string $filePath, string $fqcn): string
    {
        if (!class_exists($fqcn)) {
            return "Classe introuvable pour extraction.";
        }

        $reflection = new \ReflectionClass($fqcn);
        $methodsInfo = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // Exclure le constructeur ou les méthodes héritées de TestCase/Object si besoin
            if ($method->isConstructor()) {
                $params = [];
                foreach ($method->getParameters() as $param) {
                    $type = $param->getType();
                    $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';
                    $params[] = sprintf('%s $%s', $typeName, $param->getName());
                }
                $methodsInfo[] = sprintf("Constructeur : __construct(%s)", implode(', ', $params));
                continue;
            }

            $returnType = $method->getReturnType();
            $returnTypeStr = $returnType instanceof \ReflectionNamedType ? $returnType->getName() : 'mixed';
            $methodsInfo[] = sprintf("- public function %s(...): %s", $method->getName(), $returnTypeStr);
        }

        return sprintf(
            "Cible d'analyse : %s\nFichier : %s\n\nSignature des méthodes publiques :\n%s",
            $fqcn,
            $filePath,
            implode("\n", $methodsInfo)
        );
    }
}