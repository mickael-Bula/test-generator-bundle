<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Util;

final class JsonSanitizer
{
    public function sanitizeLlmJsonResponse(string $rawContent): string
    {
        // 1. Nettoyage des balises Markdown (```json ... ```)
        $json = preg_replace('/^```(?:json|php)?\s*/i', '', $rawContent);
        $json = preg_replace('/\s*```$/', '', (string) $json);
        $json = trim((string) $json);

        // 2. Échappement des caractères de contrôle dans les chaînes JSON
        return (string) preg_replace_callback(
            '/"(?:[^"\\\\]|\\\\.)*"/',
            static fn (array $matches): string => str_replace(
                ["\r\n", "\n", "\r", "\t"],
                ['\n', '\n', '\n', '\t'],
                $matches[0]
            ),
            $json
        );
    }
}
