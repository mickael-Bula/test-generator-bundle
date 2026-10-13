<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final class ExpectsOnNonMockInterceptor implements ErrorInterceptorInterface
{
    public function supports(string $errorOutput): bool
    {
        return str_contains($errorOutput, 'Call to undefined method')
            && (str_contains($errorOutput, 'expects()') || str_contains($errorOutput, 'method()'));
    }

    public function intercept(string $errorOutput): ?string
    {
        if (!preg_match('/Call to undefined method\s+([^\:]+)::(expects|method)\(\)/i', $errorOutput,$matches)) {
            return null;
        }

        $className =$matches[1];
        $methodCalled =$matches[2];

        return sprintf(
            "INDICE DE CORRECTION CRITIQUE (INTERDICTION DE CONFIGURER DES ATTENTES SUR UN OBJET RÉEL) :\n" .
            "Erreur fatale : Tu tentes d'appeler `%s()` sur la classe concrète ou non mockée `%s`.\n" .
            "Règle fondamentale PHPUnit : Les méthodes d'attente (`expects`, `method`) n'existent **que sur les mocks** (générés via `createMock()`).\n" .
            "Tu ne peux **jamais** configurer d'attentes sur un objet réel (que ce soit la SUT, un DTO, ou un service).\n\n" .
            "❌ **Code erroné à supprimer ou réécrire** :\n" .
            "```php\n" .
            "// Interdit sur un objet réel ou la SUT :\n" .
            "\$instance->expects(self::once())->method('...');\n" .
            "```\n\n" .
            "✔️ **Règle** :\n" .
            "- S'il s'agit de la SUT (`\$this->sut`), appelle directement sa vraie méthode sans `expects()`.\n" .
            "- S'il s'agit d'une dépendance externe, elle doit impérativement être déclarée et initialisée comme un mock dans ton `setUp()` pour accepter `expects()`.",
            $methodCalled,$className
        );
    }
}