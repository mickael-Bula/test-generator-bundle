<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Validator;

use PhpParser\Error;
use PhpParser\Parser;
use PhpParser\ParserFactory;

class PhpSyntaxValidator
{
    private Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        // En PhpParser v5, on instancie le parseur via la factory
        $this->parser = $parser ?? (new ParserFactory())->createForHostVersion();
    }

    /**
     * Valide la syntaxe du code PHP fourni sous forme de chaîne.
     *
     * @return SyntaxValidationResult Contient le statut et l'erreur éventuelle
     */
    public function validate(string $phpCode): SyntaxValidationResult
    {
        try {
            $this->parser->parse($phpCode);

            return SyntaxValidationResult::success();
        } catch (Error $error) {
            return SyntaxValidationResult::failure(
                message: $error->getRawMessage(),
                line: $error->getStartLine()
            );
        }
    }
}
