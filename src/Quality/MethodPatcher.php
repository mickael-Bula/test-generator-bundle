<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Quality;

use Mika\TestGeneratorBundle\Interceptor\Support\SourceSpan;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;

final readonly class MethodPatcher
{
    public function __construct(private Parser $parser) {}

    /**
     * @return list<string> noms de toutes les méthodes de la première classe du fichier
     */
    public function names(string $code): array
    {
        return array_keys($this->parse($code)['methods'] ?? []);
    }

    /**
     * Code d'une méthode (attributs compris), indentation ramenée à zéro ; null si elle n'existe pas.
     */
    public function extract(string $code, string $name): ?string
    {
        $method = $this->parse($code)['methods'][$name] ?? null;

        return null === $method ? null : $this->zeroBase($this->text($code, $method));
    }

    /**
     * Tout ce qui n'est pas un test (propriétés, constantes, setUp(), helpers, data providers) : le contexte du LLM.
     */
    public function environment(string $code): string
    {
        $parsed = $this->parse($code);
        if (null === $parsed) {
            return '';
        }

        $parts = [];
        foreach ($parsed['class']->stmts as $member) {
            if ($member instanceof Node\Stmt\ClassMethod && $this->isTest($member)) {
                continue;
            }
            $parts[] = $this->zeroBase($this->text($code, $member));
        }

        return implode("\n\n", $parts);
    }

    /**
     * Remplace une méthode par $method (indentation à zéro, voir fromAnswer()).
     * Null si la méthode est absente ou si le résultat n'est pas un PHP valide qui contient encore cette méthode.
     */
    public function replace(string $code, string $name, string $method): ?string
    {
        $old = $this->parse($code)['methods'][$name] ?? null;
        if (null === $old) {
            return null;
        }

        $patched = substr_replace(
            $code,
            $this->indent($method, SourceSpan::indentAt($code, $old->getStartFilePos())),
            $old->getStartFilePos(),
            $old->getEndFilePos() - $old->getStartFilePos() + 1,
        );

        return isset($this->parse($patched)['methods'][$name]) ? $patched : null;
    }

    /**
     * Retire des méthodes, avec leur ligne et sans laisser de doubles lignes vides.
     *
     * @param list<string> $names
     */
    public function remove(string $code, array $names): string
    {
        $methods = $this->parse($code)['methods'] ?? [];

        $spans = [];
        foreach ($names as $name) {
            if (isset($methods[$name])) {
                $spans[$methods[$name]->getStartFilePos()] = $methods[$name]->getEndFilePos();
            }
        }

        krsort($spans); // de la fin vers le début : les positions restent valides
        foreach ($spans as $start => $end) {
            [$from, $length] = SourceSpan::lines($code, $start, $end);
            $code = substr_replace($code, '', $from, $length);
        }

        return $code;
    }

    /**
     * Extrait la méthode $name de la réponse d'un LLM : méthode seule (avec ou sans `<?php`), classe ou fichier complet.
     * Null si elle est absente ou renommée (on ne devine pas) ; sinon indentation ramenée à zéro.
     */
    public function fromAnswer(string $answer, string $name): ?string
    {
        $hasClass = 1 === preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+\w+/m', $answer);
        $code = $hasClass
            ? $answer
            : "<?php\nclass __Answer\n{\n" . (string) preg_replace('/^\s*<\?php\s*/', '', $answer) . "\n}\n";

        $method = $this->parse($code)['methods'][$name] ?? null;

        return null === $method ? null : $this->zeroBase($this->text($code, $method));
    }

    /**
     * @return array{class: Node\Stmt\Class_, methods: array<string, Node\Stmt\ClassMethod>}|null
     */
    private function parse(string $code): ?array
    {
        try {
            $stmts = $this->parser->parse($code) ?? [];
        } catch (Error) {
            return null;
        }

        $class = (new NodeFinder())->findFirstInstanceOf($stmts, Node\Stmt\Class_::class);
        if (!$class instanceof Node\Stmt\Class_) {
            return null;
        }

        $methods = [];
        foreach ($class->getMethods() as $method) {
            $methods[$method->name->toString()] = $method;
        }

        return ['class' => $class, 'methods' => $methods];
    }

    private function text(string $code, Node $node): string
    {
        return substr($code, $node->getStartFilePos(), $node->getEndFilePos() - $node->getStartFilePos() + 1);
    }

    /**
     * Retire l'indentation de base. Elle se lit sur la dernière ligne (l'accolade fermante) : la première ligne
     * ne peut pas servir, `cleanLlmOutput()` en a retiré l'indentation avec `trim()`.
     */
    private function zeroBase(string $text): string
    {
        $lines = explode("\n", $text);
        if (\count($lines) < 2) {
            return $text;
        }

        $base = 1 === preg_match('/^[ \t]*/', (string) end($lines), $m) ? $m[0] : '';
        if ('' !== $base) {
            foreach ($lines as $i => $line) {
                if ($i > 0 && str_starts_with($line, $base)) {
                    $lines[$i] = substr($line, \strlen($base));
                }
            }
        }

        return implode("\n", $lines);
    }

    private function indent(string $text, string $indent): string
    {
        $lines = explode("\n", $text);
        foreach ($lines as $i => $line) {
            if ($i > 0 && '' !== trim($line)) {
                $lines[$i] = $indent . $line;
            }
        }

        return implode("\n", $lines);
    }

    private function isTest(Node\Stmt\ClassMethod $method): bool
    {
        if (!$method->isPublic() || $method->isStatic()) {
            return false;
        }
        if (str_starts_with(strtolower($method->name->toString()), 'test')) {
            return true;
        }
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if (1 === preg_match('/(?:^|\\\\)Test$/', $attr->name->toString())) {
                    return true;
                }
            }
        }

        return false;
    }
}