<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Exception;

/**
 * La vérification du test généré n'a pas pu être lancée (PHPUnit introuvable, processus impossible à démarrer…).
 * À distinguer d'un test qui s'exécute et échoue : celui-ci est un résultat, pas une exception.
 */
final class VerificationException extends \RuntimeException
{
}
