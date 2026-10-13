<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final class AttributeInstantiationInterceptor implements ErrorInterceptorInterface
{
    public function supports(string $errorOutput): bool
    {
        return str_contains($errorOutput, 'Symfony\Component\DependencyInjection\Attribute') && str_contains($errorOutput, '__construct()');
    }

    public function intercept(string $errorOutput): ?string
    {
        if (!preg_match(
            '/TypeError:\s+(Symfony\\\Component\\DependencyInjection\\\Attribute\\\[^:]+)::__construct\(\)/i',
            $errorOutput,$matches
        )) {
            return null;
        }

        $attributeClass =$matches[1];

        return sprintf(
            "INDICE DE RÉFLEXION ATTRIBUT (CRITIQUE) :\n"
            . "Tu tentes d'instancier manuellement un attribut Symfony (`%s`) dans le test.\n"
            . "Les attributs PHP (`#[...]`) ne s'instancient **jamais** avec `new` dans le code de test.\n"
            . "Si le constructeur de la classe testée attend un itérable de stratégies, passe simplement un tableau vide `[]` "
            . "ou un `new \\ArrayIterator([])` directement en argument, sans utiliser de classe d'attribut Symfony.",
            $attributeClass
        );
    }
}