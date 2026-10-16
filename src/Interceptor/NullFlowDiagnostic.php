<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use Mika\TestGeneratorBundle\Inspector\TestInstantiationInspector;
use Mika\TestGeneratorBundle\Parser\TypeErrorInfo;
use Mika\TestGeneratorBundle\Resolver\ArgumentOriginResolver;
use Mika\TestGeneratorBundle\Resolver\NullOriginResolver;

final readonly class NullFlowDiagnostic
{
    public function __construct(
        private ArgumentOriginResolver     $origin,
        private NullOriginResolver         $nullOrigin,
        private TestInstantiationInspector $tests,
    ) {}

    /**
     * @throws \ReflectionException
     */
    public function build(string $phpunitOutput, ?string $testFile, array $neighborhood): ?string
    {
        $err = TypeErrorInfo::fromOutput($phpunitOutput);
        if (!$err || $err->given !== 'null') {
            return null;
        }
        $site = $this->origin->resolve($err);
        if (!$site) {
            return null;
        }

        $md = "## Diagnostic automatique (à traiter en priorité)\n";
        $md .= sprintf("- `%s::%s()` attend `%s` pour `$%s` (argument #%d) et reçoit `null`.\n",
            $err->targetClass, $err->targetMethod, $err->expected, $err->param, $err->position);
        $md .= sprintf("- Appel dans `%s()` : `%s`\n", $site['enclosing'], $site['call']);

        foreach ($this->nullOrigin->resolve($site['arg'], $neighborhood) as $o) {
            $md .= sprintf("- Source du null : `%s::%s(): %s`\n", $o['class'], $o['getter'], $o['returnType']);
            if ($o['ctorParam']) {
                $md .= sprintf("  - valeur issue du paramètre de constructeur `%s`\n", $o['ctorParam']);
                $param = explode('$', $o['ctorParam'])[1];
                $param = preg_split('/\W/', $param)[0];
                $lines = null !== $testFile ? $this->tests->missingOrNull($testFile, $o['class'], $param) : [];
                if ($lines) {
                    $md .= sprintf("  - dans le test, `new %s(...)` n'initialise pas `%s` (ou le met à null) aux lignes %s\n",
                        (new \ReflectionClass($o['class']))->getShortName(), $param, implode(', ', $lines));
                }
            }
        }

        return $md . "\n**Contrainte** : le SUT exige une valeur non nulle. "
            . "Corrige le test : fournis cette valeur dans chaque objet renvoyé par les mocks ou passé au SUT. "
            . "Ne modifie pas le SUT.\n";
    }
}