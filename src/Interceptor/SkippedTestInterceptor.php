<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use Mika\TestGeneratorBundle\Interceptor\Support\PhpUnitFailureLocator;

/** @noinspection PhpUnused */
final readonly class SkippedTestInterceptor implements ErrorInterceptorInterface
{
    public function __construct(private PhpUnitFailureLocator $locator) {}

    public function supports(string $errorOutput): bool
    {
        return 1 === preg_match('/There w(?:as|ere) \d+ skipped tests?:|Skipped: \d+/', $errorOutput);
    }

    public function intercept(string $errorOutput): ?string
    {
        $sut = $this->locator->locate($errorOutput)->sut;
        $md = "### Tests ignorés (markTestSkipped / markTestIncomplete) : interdit\n";

        if (null !== $sut && class_exists($sut)) {
            /**
             * @throws \ReflectionException
             */
            $names = static fn (int $filter): string => implode(', ', array_map(
                static fn (\ReflectionMethod $m): string => $m->getName(),
                array_filter(
                    (new \ReflectionClass($sut))->getMethods($filter),
                    static fn (\ReflectionMethod $m): bool => !$m->isConstructor(),
                ),
            ));

            $md .= '- Méthodes PUBLIQUES du SUT (appelables dans un test) : ' . $names(\ReflectionMethod::IS_PUBLIC) . "\n"
                . '- Méthodes PRIVÉES ou PROTÉGÉES (jamais appelables) : '
                . $names(\ReflectionMethod::IS_PRIVATE | \ReflectionMethod::IS_PROTECTED) . "\n";
        }

        return $md
            . "- Test ignoré sur une méthode PUBLIQUE : retire l'ignorance et teste-la pour de bon.\n"
            . "- Test ignoré sur une méthode PRIVÉE : SUPPRIME le test (exception à la règle « ne supprime aucun test ») ; "
            . "son comportement est couvert via les méthodes publiques qui l'appellent.\n";
    }
}