<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Dto;

use Symfony\Component\Serializer\Attribute\SerializedName;

final readonly class GeneratedTestResult
{
    public function __construct(
        #[SerializedName('test_code')]
        public string $testCode,
    ) {
    }

    public function getCleanTestCode(): string
    {
        $code = str_replace(["\r\n", "\r"], "\n", $this->testCode);

        // Retrait des éventuelles balises Markdown
        $code = preg_replace('/^```php\s*/i', '', $code);
        $code = preg_replace('/```\s*$/', '', $code);

        // Gestion des doubles antislashs PHP (ex : \\InvalidArgumentException pour \InvalidArgumentException)
        $code = preg_replace('/\\\\\\\\([a-zA-Z_\x7f-\xff])/', '\\\\$1', $code);

        return trim($code);
    }
}
