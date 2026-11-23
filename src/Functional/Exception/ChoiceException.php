<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Exception;

/**
 * La réponse du choix d'élément (LLM ou autre) est inexploitable.
 */
final class ChoiceException extends \RuntimeException
{
}
