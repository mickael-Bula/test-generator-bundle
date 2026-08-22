<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\RepoMap;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

class RepoMapVisitor extends NodeVisitorAbstract
{
    private string $currentClass = '';

    /** @var array<string, list<string>> */
    private array $signatures = [];
    private Standard $printer;

    public function __construct()
    {
        // Le printer permet de convertir n'importe quel nœud de type en chaîne PHP valide.
        $this->printer = new Standard();
    }

    public function enterNode(Node $node): int|Node|array|null
    {
        if ($node instanceof ClassLike && isset($node->namespacedName)) {
            $prefix = '';
            $kind = 'class';

            if ($node instanceof Class_) {
                if ($node->isFinal()) {
                    $prefix .= 'final ';
                } elseif ($node->isAbstract()) {
                    $prefix .= 'abstract ';
                }

                if ($node->isReadonly()) {
                    $prefix .= 'readonly ';
                }
            } elseif ($node instanceof Interface_) {
                $kind = 'interface';
            } elseif ($node instanceof Trait_) {
                $kind = 'trait';
            } elseif ($node instanceof Enum_) {
                $kind = 'enum';
            }

            // Ex : "final class Mika\TestGeneratorBundle\Resolver\SpecPathResolver"
            $this->currentClass = sprintf('%s%s %s', $prefix, $kind, $node->namespacedName->toString());
            $this->signatures[$this->currentClass] = [];
        }

        if ($node instanceof ClassMethod && '' !== $this->currentClass) {
            $params = [];
            foreach ($node->params as $param) {
                // Utilisation du printer pour les types de paramètres
                $type = $param->type ? $this->printer->prettyPrint([$param->type]) . ' ' : '';
                $params[] = $type . '$' . $param->var->name;
            }

            // Utilisation du printer pour le type de retour
            $returnType = $node->returnType ? ': ' . $this->printer->prettyPrint([$node->returnType]) : '';

            // Détermination explicite de la visibilité
            $visibility = 'public';
            if ($node->isProtected()) {
                $visibility = 'protected';
            } elseif ($node->isPrivate()) {
                $visibility = 'private';
            }

            $methodSignature = sprintf(
                '  - %s function %s(%s)%s',
                $visibility,
                $node->name->toString(),
                implode(', ', $params),
                $returnType
            );

            $this->signatures[$this->currentClass][] = $methodSignature;
        }

        return null;
    }

    public function leaveNode(Node $node): int|Node|array|null
    {
        if ($node instanceof ClassLike) {
            $this->currentClass = '';
        }

        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getMap(): array
    {
        return $this->signatures;
    }
}
