<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\ModelCatalog;

use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;

final readonly class PermissiveModelCatalog implements ModelCatalogInterface
{
    public function supports(): bool
    {
        return true;
    }

    public function getModel(string $modelName): CompletionsModel
    {
        return new CompletionsModel($modelName);
    }

    public function getModels(): array
    {
        return [];
    }
}
