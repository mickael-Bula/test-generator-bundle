<?php

namespace Mika\TestGeneratorBundle\ModelCatalog;

use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;

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