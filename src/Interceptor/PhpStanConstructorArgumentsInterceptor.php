<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final class PhpStanConstructorArgumentsInterceptor implements ErrorInterceptorInterface
{
    // « - Ligne 233 : Class App\Entity\Position constructor invoked with 2 parameters, 0 required. »
    private const PATTERN = '/Ligne\s+(?<line>\d+)\s*:\s*Class (?<class>[\w\\\\]+) constructor invoked with (?<given>\d+) parameters?, (?<required>\d+(?:-\d+)?) required\./';

    public function supports(string $errorOutput): bool
    {
        return 1 === preg_match(self::PATTERN, $errorOutput);
    }

    public function intercept(string $errorOutput): ?string
    {
        preg_match_all(self::PATTERN, $errorOutput, $matches, PREG_SET_ORDER);

        $byClass = [];
        foreach ($matches as $e) {
            $byClass[$e['class']][] = $e;
        }

        $md = "### Arguments de constructeur incorrects (relevé par PHPStan)\n";
        foreach ($byClass as $class => $errors) {
            $md .= sprintf(
                "\n- `\\%s` : lignes %s du test (appelé avec %s argument(s), attendu %s).\n",
                $class,
                implode(', ', array_map(static fn (array $e): string => $e['line'], $errors)),
                $errors[0]['given'],
                $errors[0]['required'],
            );

            if (!class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            $constructor = $reflection->getConstructor();

            if (null === $constructor) {
                $md .= "  - Cette classe n'a AUCUN constructeur : PHP ignore sans erreur les arguments passés à `new`. Le test passe, "
                    . "mais les valeurs ne sont PAS appliquées à l'objet.\n"
                    . "  - Correction : `\$x = new \\$class();` puis un mutateur par valeur :\n";
                foreach ($this->mutators($reflection) as $signature) {
                    $md .= "      $signature\n";
                }
            } else {
                $md .= sprintf("  - Signature réelle : `__construct(%s)`\n", implode(', ', array_map(
                    static fn (\ReflectionParameter $p): string => trim(($p->getType() ?? '') . ' $' . $p->getName()
                        . ($p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : '')),
                    $constructor->getParameters(),
                )));
            }
        }

        return $md . "\nNe modifie ni la structure du test ni ses assertions : corrige seulement la construction de ces objets.\n";
    }

    /** @return list<string> */
    private function mutators(\ReflectionClass $class): array
    {
        $signatures = [];
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (1 === preg_match('/^(set|add)[A-Z]/', $method->getName()) && 1 === $method->getNumberOfParameters()) {
                $parameter = $method->getParameters()[0];
                $signatures[] = sprintf('%s(%s)', $method->getName(), trim(($parameter->getType() ?? '') . ' $' . $parameter->getName()));
            }
        }

        return \array_slice($signatures, 0, 20);
    }
}