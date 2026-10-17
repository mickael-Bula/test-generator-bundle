<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Renderer;

final class SkeletonRenderer
{
    /**
     * @throws \ReflectionException
     */
    public function render(string $fqcn, int $depth): string
    {
        $rc  = new \ReflectionClass($fqcn);
        $out = $this->doc($rc->getDocComment() ?: '', $depth)
            . $this->header($rc) . " {\n";

        foreach ($rc->getTraitNames() as $t) $out .= "    use \\$t;\n";

        $filter = $depth === 0
            ? \ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED
            : \ReflectionMethod::IS_PUBLIC;

        foreach ($rc->getMethods($filter) as $m) {
            $inherited = $m->getDeclaringClass()->getName() !== $fqcn
                ? "    // hérité de {$m->getDeclaringClass()->getName()}\n" : '';
            $out .= $inherited
                . $this->doc($m->getDocComment() ?: '', $depth, '    ')
                . '    ' . $this->signature($m) . ";\n";
        }
        return $out . "}\n";
    }

    private function doc(string $doc, int $depth, string $indent = ''): string
    {
        if ('' === $doc) {
            return '';
        }

        if (0 === $depth) {
            return $indent . $doc . "\n";
        }

        $keep = array_filter(
            explode("\n", $doc),
            fn($l) => preg_match('/@(param|return|var|throws|template)/', $l)
        );
        // Profondeur 2 : on ne garde que les types riches (génériques, null)
        if ($depth >= 2) {
            $keep = array_filter($keep, fn($l) => preg_match('/[<{|]/', $l));
        }
        return $keep
            ? $indent . "/**\n" . implode("\n", array_map(fn($l) => $indent . ' ' . trim($l), $keep)) . "\n$indent */\n"
            : '';
    }

    private function header(\ReflectionClass $rc): string
    {
        $kind = $rc->isInterface() ? 'interface' : ($rc->isTrait() ? 'trait' : ($rc->isEnum() ? 'enum' : 'class'));

        $s = ($rc->isFinal() ? 'final ' : '')
            . ($rc->isAbstract() && !$rc->isInterface() ? 'abstract ' : '')
            . "$kind {$rc->getName()}";

        if ($p = $rc->getParentClass()) {
            $s .= " extends {$p->getName()}";
        }
        if ($i = $rc->getInterfaceNames()) {
            $s .= ' implements ' . implode(', ', $i);
        }
        return $s;
    }

    /**
     * @throws \ReflectionException
     */
    private function signature(\ReflectionMethod $m): string
    {
        $params = array_map(fn($p) => trim(sprintf('%s %s$%s%s',
            $p->getType() ?? '', $p->isVariadic() ? '...' : '', $p->getName(),
            $p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : '')),
            $m->getParameters());

        return sprintf('%s%s function %s(%s)%s',
            $m->isStatic() ? 'static ' : '',
            $m->isPublic() ? 'public' : 'protected',
            $m->getName(), implode(', ', $params),
            $m->hasReturnType() ? ': ' . $m->getReturnType() : '');
    }
}
