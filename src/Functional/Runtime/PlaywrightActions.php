<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Runtime;

use Mika\TestGeneratorBundle\Functional\Text\TextNormalizer;

/**
 * Seul endroit du bundle qui touche à l'API de Playwright PHP (encore en 0.x) : attentes par
 * scrutation, sélection, case à cocher. Si l'API change, c'est ici qu'on adapte.
 *
 * Les paramètres sont volontairement typés « object » : ils reçoivent Playwright\Page\PageInterface
 * et Playwright\Locator\LocatorInterface, et les tests du bundle utilisent de faux objets.
 * Seules les méthodes suivantes sont utilisées : locator(), click(), isVisible(), innerText(), url(),
 * evaluate(), waitForLoadState(), et, si elles existent, selectOption(), check(), uncheck(), isChecked().
 */
final class PlaywrightActions
{
    private const SETTLE_SCRIPT = "() => document.body ? (document.body.innerText.length + ':' + document.querySelectorAll('*').length) : '0'";

    public function __construct(
        private readonly int $timeoutMs = 5000,
        private readonly int $pollMs = 100,
    ) {
    }

    /**
     * @param object $locator Playwright\Locator\LocatorInterface
     */
    public function waitUntilVisible(object $locator): void
    {
        $this->waitUntil(static fn (): bool => true === $locator->isVisible(), 'l\'élément ne s\'est pas affiché');
    }

    /**
     * @param object $locator Playwright\Locator\LocatorInterface
     */
    public function waitUntilHidden(object $locator): void
    {
        $this->waitUntil(static fn (): bool => true !== $locator->isVisible(), 'l\'élément est resté affiché');
    }

    /**
     * @param object $page Playwright\Page\PageInterface
     */
    public function waitUntilUrl(object $page, string $expected): void
    {
        $withQuery = str_contains($expected, '?');
        $this->waitUntil(
            fn (): bool => $this->comparableUrl((string) $page->url(), $withQuery) === $this->comparableUrl($expected, $withQuery),
            sprintf('l\'URL « %s » n\'a pas été atteinte (URL actuelle : %s)', $expected, $this->safe(static fn (): string => (string) $page->url())),
        );
    }

    /**
     * @param object $page Playwright\Page\PageInterface
     */
    public function waitUntilUrlContains(object $page, string $needle): void
    {
        $this->waitUntil(
            static fn (): bool => str_contains((string) $page->url(), $needle),
            sprintf('l\'URL ne contient pas « %s » (URL actuelle : %s)', $needle, $this->safe(static fn (): string => (string) $page->url())),
        );
    }

    /**
     * @param object $page Playwright\Page\PageInterface
     */
    public function waitUntilTextVisible(object $page, string $text): void
    {
        $needle = TextNormalizer::clean($text);
        $this->waitUntil(fn (): bool => str_contains($this->visibleText($page), $needle), sprintf('le texte « %s » n\'est pas apparu', $text));
    }

    /**
     * @param object $page Playwright\Page\PageInterface
     */
    public function waitUntilTextGone(object $page, string $text): void
    {
        $needle = TextNormalizer::clean($text);
        $this->waitUntil(fn (): bool => !str_contains($this->visibleText($page), $needle), sprintf('le texte « %s » est resté affiché', $text));
    }

    /**
     * Texte visible de la page, sans glyphe d'icône et à espaces réduits.
     *
     * @param object $page Playwright\Page\PageInterface
     */
    public function visibleText(object $page): string
    {
        return TextNormalizer::clean((string) $page->locator('body')->innerText());
    }

    /**
     * Chemin d'une URL, avec sa requête si demandé, sans barre finale (sauf la racine).
     */
    public function comparableUrl(string $url, bool $withQuery): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = '' === $path ? '/' : $path;
        if ('/' !== $path) {
            $path = rtrim($path, '/');
        }

        $query = (string) parse_url($url, PHP_URL_QUERY);

        return $withQuery && '' !== $query ? $path . '?' . $query : $path;
    }

    /**
     * @param object $locator Playwright\Locator\LocatorInterface (une liste <select>)
     */
    public function selectValue(object $locator, string $value): void
    {
        if (!method_exists($locator, 'selectOption')) {
            throw new \BadMethodCallException('Le locator Playwright n\'expose pas selectOption() : adapter PlaywrightActions::selectValue().');
        }

        try {
            $locator->selectOption($value);
        } catch (\TypeError) {
            $locator->selectOption([$value]);
        }
    }

    /**
     * @param object $locator Playwright\Locator\LocatorInterface (une case à cocher)
     */
    public function setChecked(object $locator, bool $checked): void
    {
        $method = $checked ? 'check' : 'uncheck';
        if (method_exists($locator, $method)) {
            $locator->{$method}();

            return;
        }

        if (method_exists($locator, 'isChecked')) {
            if ($locator->isChecked() !== $checked) {
                $locator->click();
            }

            return;
        }

        throw new \BadMethodCallException(sprintf('Le locator Playwright n\'expose ni %s() ni isChecked() : adapter PlaywrightActions::setChecked().', $method));
    }

    /**
     * Attend que la page soit calme : réseau inactif puis DOM stable (deux relevés identiques).
     * Réservé à la sonde ; les tests générés attendent des résultats précis, pas le calme.
     *
     * @param object $page Playwright\Page\PageInterface
     */
    public function settle(object $page, int $maxMs = 3000): void
    {
        try {
            $page->waitForLoadState('networkidle');
        } catch (\Throwable) {
            // pages à connexions longues (SSE, polling) : on se contente de la stabilité du DOM
        }

        $deadline = microtime(true) + $maxMs / 1000;
        $previous = null;

        do {
            try {
                $signature = (string) $page->evaluate(self::SETTLE_SCRIPT);
            } catch (\Throwable) {
                $signature = null; // navigation en cours
            }

            if (null !== $signature && $signature === $previous) {
                return;
            }

            $previous = $signature;
            usleep($this->pollMs * 1000);
        } while (microtime(true) < $deadline);
    }

    /**
     * @param \Closure(): bool $condition
     */
    private function waitUntil(\Closure $condition, string $failure): void
    {
        $deadline = microtime(true) + $this->timeoutMs / 1000;

        do {
            try {
                if ($condition()) {
                    return;
                }
            } catch (\Throwable) {
                // page en transition : on réessaie jusqu'à l'échéance
            }

            usleep($this->pollMs * 1000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException(sprintf('Délai de %d ms dépassé : %s.', $this->timeoutMs, $failure));
    }

    /**
     * @param \Closure(): string $read
     */
    private function safe(\Closure $read): string
    {
        try {
            return $read();
        } catch (\Throwable) {
            return 'inconnue';
        }
    }
}
