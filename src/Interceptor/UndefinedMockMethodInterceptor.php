<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final readonly class UndefinedMockMethodInterceptor implements ErrorInterceptorInterface
{
    private const PATTERN = '/Call to an undefined method (?<class>[\w\\\\]+)::(?:expects|method)\(\)'
    . '|Property [\w\\\\]+::\$\w+ has unresolvable native type/';

    public function supports(string $errorOutput): bool
    {
        return 1 === preg_match(self::PATTERN, $errorOutput);
    }

    public function intercept(string $errorOutput): ?string
    {
        preg_match_all(self::PATTERN, $errorOutput, $m);

        $md = "### `expects()` / `method()` inconnus de PHPStan\n"
            . "Cause : le mock est stocké dans une variable ou propriété typée avec la classe seule.\n"
            . "Correction : si la classe est final, utilise `\PHPUnit\Framework\MockObject\MockObject|\Classe` ; "
            . "sinon `\Classe&\PHPUnit\Framework\MockObject\MockObject` :\n";
        foreach (array_unique($m['class']) as $class) {
            $md .= sprintf("- `private \\%s&\\PHPUnit\\Framework\\MockObject\\MockObject \$mock;`\n", $class);
        }

        return $md;
    }
}
