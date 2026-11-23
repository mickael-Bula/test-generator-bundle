<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Dto;

use Mika\TestGeneratorBundle\Functional\Enum\ExpectationType;

final readonly class Expectation
{
    /**
     * @param string|null $subject URL, texte, titre de panneau ou nom de table selon le type
     * @param int|null    $count   nombre de lignes attendu (écart signé pour RowsDelta)
     */
    public function __construct(
        public int $index,
        public ExpectationType $type,
        public ?string $subject,
        public ?int $count,
        public string $raw,
        public int $line,
    ) {
    }
}
