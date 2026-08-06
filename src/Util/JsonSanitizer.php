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

        // 2. Si le JSON est déjà parfaitement valide, on le renvoie immédiatement
        if (json_validate($json)) {
            return $json;
        }

        // 3. Traitement caractère par caractère pour corriger les échappements et sauts de ligne
        $length = strlen($json);
        $result = '';
        $inString = false;
        $isEscaped = false;

        for ($i = 0; $i < $length; ++$i) {
            $char = $json[$i];

            if ($inString) {
                if ($isEscaped) {
                    $result .= $char;
                    $isEscaped = false;
                    continue;
                }

                if ('\\' === $char) {
                    // Vérification du caractère suivant pour s'assurer que c'est un échappement JSON valide
                    $nextChar = $i + 1 < $length ? $json[$i + 1] : '';
                    if (in_array($nextChar, ['"', '\\', '/', 'b', 'f', 'n', 'r', 't', 'u'], true)) {
                        $result .= $char;
                        $isEscaped = true;
                    } else {
                        // Antislash PHP non échappé (ex: \App) -> on le double
                        $result .= '\\\\';
                    }
                    continue;
                }

                if ('"' === $char) {
                    $result .= $char;
                    $inString = false;
                    continue;
                }

                // Normalisation des caractères de contrôle bruts
                $result .= match ($char) {
                    "\n" => '\n',
                    "\r" => '\r',
                    "\t" => '\t',
                    default => $char,
                };
            } else {
                if ('"' === $char) {
                    $inString = true;
                }
                $result .= $char;
            }
        }

        return $result;
    }
}
