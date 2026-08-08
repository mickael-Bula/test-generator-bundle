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

        // Retrait des éventuelles balises Markdown (avec ou sans "php")
        $code = preg_replace('/^```(?:php)?\s*/i', '', $code);
        $code = preg_replace('/```\s*$/', '', $code);

        // Gestion des doubles antislashs PHP (ex : \\InvalidArgumentException -> \InvalidArgumentException)
        $code = preg_replace('/\\\\\\\\([a-zA-Z_\x7f-\xff])/', '\\\\$1', $code);

        // Correction des apostrophes échappées dans des chaînes délimitées par des apostrophes (ex : : '... n\'est ...' → "... n'est ...")
        $code = preg_replace_callback("/'((?:[^'\\\\]|\\\\.)*?\\\\'[\s\S]*?)'/", static function (array $matches): string {
            $inner = str_replace("\\'", "'", $matches[1]);

            return '"' . $inner . '"';
        }, $code);

        return trim($code);
    }
}
