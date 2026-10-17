<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Interceptor;

use Mika\TestGeneratorBundle\Service\TestSnapshotStore;

/** @noinspection PhpUnused */
final readonly class GenericTypeErrorInterceptor implements ErrorInterceptorInterface
{
    private const PATTERN = '/TypeError:\s+(?<class>[\w\\\\]+)::(?<method>\w+)\(\):\s+Argument\s+#(?<pos>\d+)\s+\(\$(?<arg>\w+)\)\s+must be of type\s+(?<expected>[^,\r\n]+),\s+(?<given>\w+)\s+given(?:,\s+called in\s+(?<file>\S+)\s+on line\s+(?<line>\d+))?/';

    public function __construct(private TestSnapshotStore $snapshots){}

    public function supports(string $errorOutput): bool
    {
        return [] !== $this->errors($errorOutput);
    }

    public function intercept(string $errorOutput): ?string
    {
        $blocks = array_map($this->describe(...), $this->errors($errorOutput));

        return [] === $blocks ? null : implode("\n", $blocks);
    }

    /**
     * `null given` est laissé à NullArgumentTypeErrorInterceptor (diagnostic de flux).
     */
    private function errors(string $output): array
    {
        preg_match_all(self::PATTERN, $output, $matches, PREG_SET_ORDER);

        return array_values(array_filter($matches, static fn (array $e): bool => 'null' !== strtolower($e['given'])));
    }

    private function describe(array $e): string
    {
        $expected = trim($e['expected']);
        $md = sprintf("### TypeError : `%s::%s()`, argument #%s `\$%s`\n", $e['class'], $e['method'], $e['pos'], $e['arg'])
            . sprintf("- Reçu : `%s` ; attendu : `%s` (aucune conversion automatique : `strict_types=1`)\n", $e['given'], $expected);

        if (null !== $signature = $this->signature($e['class'], $e['method'])) {
            $md .= "- Signature réelle : `$signature`\n";
        }
        if (null !== $line = $this->offendingLine($e['file'] ?? '', (int) ($e['line'] ?? 0))) {
            $md .= "- Ligne fautive du test : `$line`\n";
        }

        return $md . '- Correction : ' . $this->advice($expected, $e['given']) . "\n";
    }

    private function advice(string $expected, string $given): string
    {
        $type = ltrim($expected, '?');
        $bare = strtolower($type);

        return match (true) {
            str_contains($expected, '|') => "choisis une valeur dont le type fait partie de `$expected`.",
            'string' === $bare && \in_array($given, ['int', 'float'], true) => "passe la valeur entre apostrophes (ex. `'100'`), jamais un nombre.",
            'int' === $bare && \in_array($given, ['string', 'float'], true) => 'passe un entier littéral (ex. `10`), sans apostrophes ni décimales.',
            'float' === $bare && 'int' === $given => 'passe un flottant littéral (ex. `100.0`).',
            'float' === $bare && 'string' === $given => 'retire les apostrophes (ex. `100.5`).',
            \in_array($bare, ['array', 'iterable'], true) => 'passe un tableau littéral.',
            is_a($type, \DateTimeInterface::class, true) => "passe une instance, par exemple `new \\DateTimeImmutable('2026-01-15')`.",
            class_exists($type) || interface_exists($type) => "passe une instance de `\\$type` (voir son constructeur dans le contexte), pas une valeur scalaire.",
            default => 'passe une valeur du type exact attendu.',
        };
    }

    private function signature(string $class, string $method): ?string
    {
        if (1 === preg_match('/MockObject_(.+)_[a-f0-9]{8}/i', $class, $m) && (class_exists($m[1]) || interface_exists($m[1]))) {
            $class = $m[1];
        }

        try {
            $reflection = new \ReflectionClass($class);
        } catch (\ReflectionException) {
            return null;
        }
        if (!$reflection->hasMethod($method)) {
            return null;
        }

        return sprintf('%s(%s)', $method, implode(', ', array_map(
            static fn (\ReflectionParameter $p): string => trim(
                ($p->getType() ?? '') . ' $' . $p->getName()
                . ($p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : ''),
            ),
            $reflection->getMethod($method)->getParameters(),
        )));
    }

    private function offendingLine(string $file, int $line): ?string
    {
        if ('' === $file || $line < 1) {
            return null;
        }
        // Le runner supprime le test après exécution : l'instantané conserve le code qui a produit l'erreur
        $path = is_file($file) ? $file : $this->snapshots->find($file);
        $lines = null === $path ? [] : (file($path, FILE_IGNORE_NEW_LINES) ?: []);

        return isset($lines[$line - 1]) ? trim($lines[$line - 1]) : null;
    }
}