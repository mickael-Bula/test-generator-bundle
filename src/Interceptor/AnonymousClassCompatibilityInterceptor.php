<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use Mika\TestGeneratorBundle\Resolver\MissingClassResolver;

/** @noinspection PhpUnused */
final readonly class AnonymousClassCompatibilityInterceptor implements ErrorInterceptorInterface
{
    // « …between class@anonymous::execute(App\X $x): void and App\Contract::execute(App\X $x): void, because class App\Tests\Dynamic\X is not available »
    private const PATTERN = '/Could not check compatibility between (?<left>.+?) and (?<right>.+?), because (?<kind>[a-z]+) (?<missing>[\w\\\\]+) is not available/';

    public function __construct(private MissingClassResolver $resolver) {}

    public function supports(string $errorOutput): bool
    {
        return 1 === preg_match(self::PATTERN, $errorOutput);
    }

    public function intercept(string $errorOutput): ?string
    {
        if (1 !== preg_match(self::PATTERN, $errorOutput, $m)) {
            return null;
        }

        $short = basename(str_replace('\\', '/', $m['missing']));
        [, $candidates] = $this->resolver->resolve($short, null);

        $real = match (true) {
            1 === \count($candidates) => sprintf("- Classe réelle : `\\%s`\n", $candidates[0]),
            [] !== $candidates => '- Classes de ce nom : ' . implode(', ', array_map(static fn (string $c): string => "`\\$c`", $candidates)) . "\n",
            default => '',
        };

        return "### Classe anonyme : signature incompatible avec l'interface (erreur fatale)\n"
            . sprintf(
                "- Cause : le nom court `%s` n'est pas importé dans la signature de ta classe anonyme. "
                . "PHP l'a résolu dans le namespace du test (`%s`), qui n'existe pas.\n", $short, $m['missing']
            )
            . $real
            . sprintf("- Signature de ta classe anonyme : `%s`\n", $m['left'])
            . sprintf("- Signature attendue par l'interface (noms déjà complets) : `%s`\n", $m['right'])
            . "- Correction : recopie la signature de l'interface en préfixant CHAQUE classe d'un antislash (`\\App\\Entity\\X`), "
            . "arguments et type de retour compris.\n";
    }
}