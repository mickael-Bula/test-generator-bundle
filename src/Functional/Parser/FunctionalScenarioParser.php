<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Parser;

use Mika\TestGeneratorBundle\Functional\Dto\Expectation;
use Mika\TestGeneratorBundle\Functional\Dto\FunctionalScenario;
use Mika\TestGeneratorBundle\Functional\Dto\ScenarioContext;
use Mika\TestGeneratorBundle\Functional\Dto\Step;
use Mika\TestGeneratorBundle\Functional\Enum\ExpectationType;
use Mika\TestGeneratorBundle\Functional\Enum\StepAction;
use Mika\TestGeneratorBundle\Functional\Exception\ScenarioParseException;
use Mika\TestGeneratorBundle\Functional\Text\TextNormalizer;

/**
 * Transforme un scénario Markdown écrit à la main en FunctionalScenario, sans LLM.
 *
 * Format : un titre « # », puis les sections « ## Contexte », « ## Étapes » et « ## Attentes ».
 * Les valeurs et libellés se placent entre « guillemets français ».
 */
final class FunctionalScenarioParser
{
    private const QUOTED = '/«\s*(.*?)\s*»|"([^"]*)"|\x{201C}([^\x{201D}]*)\x{201D}/us';

    private const ARTICLE = "(?:(?:le|la|les|un|une)\\s+|l')?";

    private const VERBS_HELP = 'Aller sur, Cliquer sur, Saisir … dans, Remplir … avec, Sélectionner … dans, Cocher, Décocher';

    public function parseFile(string $path): FunctionalScenario
    {
        $content = @file_get_contents($path);
        if (false === $content) {
            throw new \InvalidArgumentException(sprintf('Le scénario "%s" est illisible.', $path));
        }

        return $this->parse($content, $path);
    }

    public function parse(string $markdown, string $sourcePath = ''): FunctionalScenario
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $markdown = ltrim($markdown, "\u{FEFF}");

        $title = '';
        $section = null;
        $contextData = ['strategy' => null, 'profile' => null, 'start' => '/'];
        $steps = [];
        $expectations = [];

        foreach (explode("\n", $markdown) as $offset => $rawLine) {
            $lineNumber = $offset + 1;
            $line = trim($rawLine);

            if ('' === $line || str_starts_with($line, '<!--') || str_starts_with($line, '---')) {
                continue;
            }

            if (1 === preg_match('/^(#{1,6})\s+(.+)$/u', $line, $heading)) {
                if (1 === strlen($heading[1])) {
                    $title = '' === $title ? trim($heading[2]) : $title;
                    continue;
                }

                $section = $this->sectionOf($heading[2], $lineNumber);
                continue;
            }

            if (null === $section) {
                continue; // description libre sous le titre
            }

            if (1 !== preg_match('/^(?:\d+[.)]|[-*+])\s+(.+)$/u', $line, $item)) {
                throw ScenarioParseException::atLine($lineNumber, 'élément de liste attendu (« 1. … » ou « - … »)', $line);
            }

            $text = trim($item[1]);

            match ($section) {
                'context' => $this->parseContextLine($text, $lineNumber, $contextData),
                'steps' => $steps[] = $this->parseStep($text, count($steps) + 1, $lineNumber),
                'expectations' => $expectations[] = $this->parseExpectation($text, count($expectations) + 1, $lineNumber),
            };
        }

        if ([] === $steps) {
            throw new ScenarioParseException('Le scénario ne contient aucune étape (section « ## Étapes »).');
        }

        if ([] === $expectations) {
            throw new ScenarioParseException('Le scénario ne contient aucune attente (section « ## Attentes ») : un test sans assertion serait vide.');
        }

        if ('' === $title) {
            $title = '' === $sourcePath ? 'Scénario' : pathinfo($sourcePath, PATHINFO_FILENAME);
        }

        return new FunctionalScenario(
            $title,
            $sourcePath,
            new ScenarioContext($contextData['strategy'], $contextData['profile'], $contextData['start']),
            $steps,
            $expectations,
        );
    }

    private function sectionOf(string $heading, int $line): string
    {
        $key = TextNormalizer::fold($heading);

        return match (true) {
            str_starts_with($key, 'contexte') => 'context',
            str_starts_with($key, 'etape') => 'steps',
            str_starts_with($key, 'attente') => 'expectations',
            default => throw ScenarioParseException::atLine($line, 'section inconnue (attendu : Contexte, Étapes ou Attentes)', $heading),
        };
    }

    /**
     * @param array{strategy: string|null, profile: string|null, start: string} $data
     */
    private function parseContextLine(string $text, int $line, array &$data): void
    {
        [$template, $values] = $this->tokenize($text);

        if (1 !== preg_match('/^([^:]+?)\s*:\s*(.*)$/u', $template, $parts)) {
            throw ScenarioParseException::atLine($line, 'ligne de contexte attendue sous la forme « Clé : valeur »', $text);
        }

        $key = TextNormalizer::fold($parts[1]);
        $valueTemplate = trim($parts[2]);
        $valueFolded = TextNormalizer::fold($valueTemplate);

        switch (true) {
            case in_array($key, ['authentification', 'auth', 'connexion'], true):
                $data['strategy'] = match (true) {
                    str_contains($valueFolded, 'websso') => 'websso',
                    1 === preg_match('/mot de passe|password|login|identifiant/', $valueFolded) => 'password',
                    1 === preg_match('/^(aucune?|anonyme|non|sans)\b/', $valueFolded) => null,
                    default => throw ScenarioParseException::atLine($line, 'authentification inconnue (attendu : mot de passe, websso ou aucune)', $text),
                };

                if (1 === preg_match('/\{(\d+)\}/', $valueTemplate, $quoted)) {
                    $data['profile'] = $values[(int) $quoted[1]];
                } elseif (1 === preg_match('/profil\s+([\w.@-]+)/iu', $valueTemplate, $profile)) {
                    $data['profile'] = rtrim($profile[1], ',');
                }
                break;

            case in_array($key, ['profil', 'utilisateur'], true):
                $data['profile'] = 1 === preg_match('/\{(\d+)\}/', $valueTemplate, $quoted)
                    ? $values[(int) $quoted[1]]
                    : trim($valueTemplate);
                break;

            case in_array($key, ['page de depart', 'depart', 'page'], true):
                $path = 1 === preg_match('/\{(\d+)\}/', $valueTemplate, $quoted) ? $values[(int) $quoted[1]] : $valueTemplate;
                if (!str_starts_with($path, '/')) {
                    throw ScenarioParseException::atLine($line, 'la page de départ doit être un chemin commençant par « / »', $text);
                }
                $data['start'] = $path;
                break;

            default:
                throw ScenarioParseException::atLine($line, 'clé de contexte inconnue (attendu : Authentification, Profil ou Page de départ)', $text);
        }
    }

    private function parseStep(string $text, int $index, int $line): Step
    {
        [$template, $values] = $this->tokenize($text);
        $folded = TextNormalizer::fold($template);
        $article = self::ARTICLE;

        $make = static fn (StepAction $action, ?string $target, ?string $value = null, ?string $noun = null): Step => new Step($index, $action, $target, $value, $noun, $text, $line);

        // Aller sur « /chemin »
        if (1 === preg_match('/^(?:aller|naviguer|se rendre|retourner|revenir)\s+(?:sur|a|vers)\s+(?:la page\s+)?(?:\{(\d+)\}|(\/\S*))$/', $folded, $m)
            || 1 === preg_match('/^ouvrir\s+(?:la page\s+)?(?:\{(\d+)\}|(\/\S*))$/', $folded, $m)) {
            $path = '' !== ($m[1] ?? '') ? $values[(int) $m[1]] : $this->originalTail($template, '/(\/\S*)$/u');
            if (!str_starts_with($path, '/')) {
                throw ScenarioParseException::atLine($line, 'la page visée doit être un chemin commençant par « / »', $text);
            }

            return $make(StepAction::Visit, $path);
        }

        // Cliquer sur [le bouton] « X »
        if (1 === preg_match('/^(?:cliquer|appuyer|presser)\s+(?:sur|dans)\s+' . $article . '(?:(bouton|lien|onglet|menu|icone|element|bascule|case|carte)\s+)?\{(\d+)\}$/', $folded, $m)) {
            return $make(StepAction::Click, $values[(int) $m[2]], null, '' === $m[1] ? null : $m[1]);
        }

        // Saisir « valeur » dans [le champ] « X »
        if (1 === preg_match('/^(?:saisir|renseigner|entrer|taper|ecrire)\s+\{(\d+)\}\s+(?:dans|pour)\s+' . $article . '(?:(?:champ|zone de texte|zone|input|saisie)\s+)?\{(\d+)\}$/', $folded, $m)) {
            return $make(StepAction::Fill, $values[(int) $m[2]], $values[(int) $m[1]]);
        }

        // Remplir [le champ] « X » avec « valeur »
        if (1 === preg_match('/^remplir\s+' . $article . '(?:(?:champ|zone de texte|zone)\s+)?\{(\d+)\}\s+avec\s+\{(\d+)\}$/', $folded, $m)) {
            return $make(StepAction::Fill, $values[(int) $m[1]], $values[(int) $m[2]]);
        }

        // Sélectionner « valeur » dans [la liste] « X »
        if (1 === preg_match('/^(?:selectionner|choisir)\s+\{(\d+)\}\s+(?:dans|pour)\s+' . $article . '(?:(?:liste deroulante|liste|menu deroulant|menu|champ|selecteur|select|zone)\s+)?\{(\d+)\}$/', $folded, $m)) {
            return $make(StepAction::Select, $values[(int) $m[2]], $values[(int) $m[1]]);
        }

        // Cocher / Décocher [la case] « X »
        if (1 === preg_match('/^(cocher|decocher)\s+' . $article . '(?:(?:case a cocher|case|option|interrupteur|bascule)\s+)?\{(\d+)\}$/', $folded, $m)) {
            return $make('cocher' === $m[1] ? StepAction::Check : StepAction::Uncheck, $values[(int) $m[2]]);
        }

        throw ScenarioParseException::atLine($line, 'étape non reconnue. Verbes acceptés : ' . self::VERBS_HELP . '. Les libellés et valeurs se placent entre « ».', $text);
    }

    private function parseExpectation(string $text, int $index, int $line): Expectation
    {
        [$template, $values] = $this->tokenize($text);
        $folded = TextNormalizer::fold($template);

        $make = static fn (ExpectationType $type, ?string $subject, ?int $count = null): Expectation => new Expectation($index, $type, $subject, $count, $text, $line);

        // L'URL contient … / L'URL est …
        if (1 === preg_match('/^l\'?url\s+(?:contient|inclut|comprend)\s+(?:\{(\d+)\}|(\S+))$/', $folded, $m)) {
            return $make(ExpectationType::UrlContains, '' !== ($m[1] ?? '') ? $values[(int) $m[1]] : $this->originalTail($template, '/(\S+)$/u'));
        }

        if (1 === preg_match('/^l\'?url\s+(?:est|vaut|doit etre|devient)\s+(?:\{(\d+)\}|(\S+))$/', $folded, $m)) {
            return $make(ExpectationType::UrlIs, $this->normalizeUrl('' !== ($m[1] ?? '') ? $values[(int) $m[1]] : $this->originalTail($template, '/(\S+)$/u')));
        }

        // Le panneau « X » est ouvert / fermé
        if (1 === preg_match('/^(?:le|la|l\')\s*(?:panneau|offcanvas|volet|dialogue|dialog|modale|modal|fenetre|popin)\s+\{(\d+)\}\s+est\s+(ouvert|ferme|affiche|visible|masque|cache)e?$/', $folded, $m)) {
            $open = in_array($m[2], ['ouvert', 'affiche', 'visible'], true);

            return $make($open ? ExpectationType::PanelOpen : ExpectationType::PanelClosed, $values[(int) $m[1]]);
        }

        // « X » n'est plus affiché
        $absent = '(?:n\'est\s+(?:plus|pas)\s+(?:affiche|visible|present)[es]*|n\'apparait\s+(?:plus|pas)|disparait|a disparu|ne s\'affiche\s+(?:plus|pas))';
        $nouns = '(?:(?:le|la|l\')\s*)?(?:(?:message|texte|libelle|titre|mention|valeur)\s+)?';
        if (1 === preg_match('/^' . $nouns . '\{(\d+)\}\s+' . $absent . '$/', $folded, $m)) {
            return $make(ExpectationType::TextAbsent, $values[(int) $m[1]]);
        }

        // « X » est affiché
        if (1 === preg_match('/^' . $nouns . '\{(\d+)\}\s+(?:(?:est|reste)\s+(?:affiche|visible|present)[es]*|apparait|s\'affiche)$/', $folded, $m)) {
            return $make(ExpectationType::TextVisible, $values[(int) $m[1]]);
        }

        // La table position contient 1 ligne de plus
        if (1 === preg_match('/^(?:la table|le tableau|la liste)\s+(?:\{(\d+)\}|([\w-]+))\s+(ne\s+)?contient\s+(?:(\d+|une?|aucune|zero)\s+)?lignes?(?:\s+(de plus|en plus|supplementaires?|de moins|en moins))?$/', $folded, $m)) {
            $table = '' !== ($m[1] ?? '') ? $values[(int) $m[1]] : $this->originalTail($template, '/(?:table|tableau|liste)\s+([\w-]+)\s+(?:ne\s+)?contient/iu', 1);
            $negated = '' !== ($m[3] ?? '');
            $word = $m[4] ?? '';

            $count = match (true) {
                '' === $word => null,
                in_array($word, ['aucune', 'zero'], true) => 0,
                in_array($word, ['un', 'une'], true) => 1,
                default => (int) $word,
            };

            if ($negated && null === $count) {
                $count = 0;
            }

            if (null === $count) {
                throw ScenarioParseException::atLine($line, 'nombre de lignes manquant (ex. « La table position contient 1 ligne de plus »)', $text);
            }

            $suffix = $m[5] ?? '';
            if ('' === $suffix) {
                return $make(ExpectationType::RowsTotal, $table, $count);
            }

            $sign = str_contains($suffix, 'moins') ? -1 : 1;

            return $make(ExpectationType::RowsDelta, $table, $sign * $count);
        }

        throw ScenarioParseException::atLine(
            $line,
            'attente non reconnue. Formes acceptées : « L\'URL est … », « L\'URL contient … », « Le message « X » est affiché », « « X » n\'est plus affiché », « Le panneau « X » est ouvert/fermé », « La table T contient N ligne(s) de plus/de moins », « La table T contient N lignes » (total).',
            $text,
        );
    }

    /**
     * Remplace chaque texte entre guillemets par {N} et renvoie les valeurs d'origine.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function tokenize(string $text): array
    {
        $values = [];
        $template = (string) preg_replace_callback(
            self::QUOTED,
            static function (array $match) use (&$values): string {
                $values[] = $match[3] ?? $match[2] ?? $match[1];

                return '{' . (count($values) - 1) . '}';
            },
            $text,
        );

        return [$template, $values];
    }

    /**
     * Une URL complète est réduite à son chemin (et sa requête) : le test ne dépend pas de l'hôte.
     */
    private function normalizeUrl(string $url): string
    {
        if (1 !== preg_match('#^https?://[^/]+(/.*)?$#i', $url, $m)) {
            return $url;
        }

        return '' === ($m[1] ?? '') ? '/' : $m[1];
    }

    /**
     * Récupère un fragment avec sa casse d'origine (la comparaison se fait sur la forme repliée).
     */
    private function originalTail(string $template, string $pattern, int $group = 1): string
    {
        return 1 === preg_match($pattern, $template, $m) ? rtrim($m[$group], '.,;') : '';
    }
}
