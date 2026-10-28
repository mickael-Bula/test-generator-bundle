<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use Mika\TestGeneratorBundle\Interceptor\Support\PhpUnitFailureLocator;
use Mika\TestGeneratorBundle\Reflection\ThrownMessages;
use Psr\Cache\InvalidArgumentException;

/**
 * Intercepteur pour les messages d'exceptions non corrigés
 *
 * @noinspection PhpUnused
 */
final readonly class ExceptionMessageInterceptor implements ErrorInterceptorInterface
{
    // language=RegExp
    private const FAILURE = '/^\d+\)\s+[\w\\\\]+::(?<test>\w+)[^\n]*\nFailed asserting that exception message \'(?<actual>.*?)\' (?:contains|is) \'(?<expected>.*)\'\.?$/m';

    public function __construct(
        private PhpUnitFailureLocator $locator,
        private ThrownMessages $thrown,
    ) {
    }

    public function supports(string $errorOutput): bool
    {
        return 1 === preg_match(self::FAILURE, str_replace("\r\n", "\n", $errorOutput));
    }

    /**
     * @throws InvalidArgumentException
     */
    public function intercept(string $errorOutput): ?string
    {
        if (0 === preg_match_all(self::FAILURE, str_replace("\r\n", "\n", $errorOutput), $failures, PREG_SET_ORDER)) {
            return null;
        }

        $sut = $this->locator->locate($errorOutput)->sut;
        $sites = null !== $sut && class_exists($sut) ? $this->thrown->of(new \ReflectionClass($sut)) : [];

        $md = "### Message d'exception différent de celui que le SUT lance\n";
        foreach ($failures as $f) {
            $md .= sprintf("\n- `%s` : attendu `%s`, obtenu `%s`.\n", $f['test'], $f['expected'], $f['actual']);

            $match = null;
            foreach ($sites as $site) {
                if (1 === preg_match($site['regex'], $f['actual'])) {
                    $match = $site;
                    break;
                }
            }

            if (null !== $match) {
                $md .= sprintf(
                    "  - Ce message est lancé par `%s()` (ligne %d) : `%s`\n",
                    $match['method'], $match['line'], $match['display']
                );
                $md .= sprintf(
                    "  - Correction : `\$this->expectExceptionMessage('%s');` "
                    . "(une sous-chaîne suffit : PHPUnit teste l'inclusion).\n",
                    addcslashes($match['stable'], "'\\")
                );
                continue;
            }

            $md .= "  - Ce message ne vient d'aucun `throw` du SUT : il vient d'une dépendance ou de PHP. "
                . "Vérifie la classe d'exception et les données du test.\n";
            foreach (\array_slice($sites, 0, 6) as $site) {
                $md .= sprintf(
                    "  - Le SUT lance `%s` dans `%s()` : `%s`\n",
                    $site['class'], $site['method'], $site['display']
                );
            }
        }

        return $md . "\nLe message se lit dans le code du SUT : ne l'invente pas.\n";
    }
}