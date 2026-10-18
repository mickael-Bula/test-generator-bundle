<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

/** @noinspection PhpUnused */
final readonly class UndefinedMockMethodInterceptor implements ErrorInterceptorInterface
{
    private const UNDEFINED = '/Call to an undefined method (?<class>[\w\\\\]+)::(?:expects|method)\(\)/';
    private const UNRESOLVABLE = '/Property (?<owner>[\w\\\\]+)::\$(?<prop>\w+) has unresolvable native type/';

    public function supports(string $errorOutput): bool
    {
        return 1 === preg_match(self::UNDEFINED, $errorOutput) || 1 === preg_match(self::UNRESOLVABLE, $errorOutput);
    }

    public function intercept(string $errorOutput): ?string
    {
        $md = '';

        if (preg_match_all(self::UNDEFINED, $errorOutput, $m)) {
            $md .= "### `expects()` / `method()` inconnus de PHPStan\n"
                . "Cause : le mock est stocké dans une propriété typée avec la classe seule.\n"
                . "Correction : type intersection (union si la classe est `final`) ; `createMock()` et les appels `expects()` ne changent pas :\n";
            foreach (array_unique($m['class']) as $class) {
                $final = class_exists($class) && (new \ReflectionClass($class))->isFinal();
                $md .= $final
                    ? sprintf("- `private \\PHPUnit\\Framework\\MockObject\\MockObject|\\%s \$mock;`\n", $class)
                    : sprintf("- `private \\%s&\\PHPUnit\\Framework\\MockObject\\MockObject \$mock;`\n", $class);
            }
        }

        if (preg_match_all(self::UNRESOLVABLE, $errorOutput, $m, PREG_SET_ORDER)) {
            $md .= "### Type natif irrésoluble pour PHPStan\n"
                . "La classe mockée est `final` : l'intersection `X&MockObject` est impossible. Utilise l'union "
                . '`\PHPUnit\Framework\MockObject\MockObject|\X` pour : '
                . implode(', ', array_map(static fn (array $e): string => '`$' . $e['prop'] . '`', $m)) . "\n";
        }

        return '' === $md ? null : $md;
    }
}
