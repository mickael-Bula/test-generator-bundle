<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use Mika\TestGeneratorBundle\Extractor\MethodSource;
use Mika\TestGeneratorBundle\Interceptor\Support\PhpUnitFailureLocator;
use Mika\TestGeneratorBundle\Interceptor\Support\RealObjectStubFinder;
use Psr\Cache\InvalidArgumentException;

/** @noinspection PhpUnused */
final readonly class StubOnRealObjectInterceptor implements ErrorInterceptorInterface
{
    private const TRIGGER = '/Call to (?:an )?undefined method [\w\\\\]+::(?:expects|method)\(\)/';

    public function __construct(
        private PhpUnitFailureLocator $locator,
        private RealObjectStubFinder $finder,
    ) {
    }

    public function supports(string $errorOutput): bool
    {
        return 1 === preg_match(self::TRIGGER, $errorOutput);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function intercept(string $errorOutput): ?string
    {
        $file = $this->locator->locate($errorOutput)->testFile;
        if (null === $file) {
            return null;
        }
        $stubs = $this->finder->find((string) file_get_contents($file));
        if ([] === $stubs) {
            return $this->fromMessage($errorOutput); // le receveur est une propriété : MockPropertyTypeFixer / UndefinedMockMethodInterceptor
        }

        $md = "### `expects()` / `method()` appelés sur un objet RÉEL\n"
            . "Ces méthodes n'existent que sur les mocks : un objet créé avec `new` n'en a pas.\n";

        foreach ($stubs as $s) {
            $md .= sprintf(
                "- `\$%s` est un `new \\%s(...)` ; stub demandé sur : %s\n",
                $s['variable'],
                $s['class'],
                implode(', ', array_map(static fn (string $m): string => "`$m()`", $s['methods'])),
            );

            if (!class_exists($s['class'])) {
                continue;
            }
            $rc = new \ReflectionClass($s['class']);
            foreach ($s['methods'] as $name) {
                if ($rc->hasMethod($name) && null !== $body = MethodSource::ofComputed($rc->getMethod($name))) {
                    $md .= "  Code réel de `$name()` :\n" . MethodSource::fenced($body, '    ') . "\n";
                }
            }
        }

        return $md
            . "Correction, dans cet ordre :\n"
            . "1. Supprime le stub et donne à l'objet l'état voulu avec ses vrais mutateurs (le code ci-dessus montre ce que le getter lit).\n"
            . "2. Si aucun mutateur ne permet cet état : `\$this->getMockBuilder(\\Classe::class)->onlyMethods(['méthode'])->getMock()`.\n"
            . "Ne change rien d'autre dans le test.\n";
    }

    /**
     * @throws InvalidArgumentException
     */
    private function fromMessage(string $output): ?string
    {
        if (0 === preg_match_all('/Call to (?:an )?undefined method (?<class>[\w\\\\]+)::(?:expects|method)\(\)/', $output, $m)) {
            return null;
        }
        $sut = $this->locator->locate($output)->sut;

        $md = "### `expects()` / `method()` appelés sur un objet qui n'est pas un mock\n";
        foreach (array_unique($m['class']) as $class) {
            $md .= sprintf("- Classe concernée : `\\%s`.\n", $class);
            $md .= $class === $sut
                ? "  - C'est la classe testée : appelle directement sa vraie méthode, sans `expects()`.\n"
                : "  - Dépendance : crée-la avec `createMock()` (propriété typée `\\{$class}&\\PHPUnit\\Framework\\MockObject\\MockObject`). "
                . "Entité ou DTO : donne-lui l'état voulu avec ses mutateurs.\n";
        }

        return $md;
    }
}