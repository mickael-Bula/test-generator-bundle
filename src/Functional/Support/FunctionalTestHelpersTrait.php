<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Support;

use Mika\TestGeneratorBundle\Functional\Runtime\PlaywrightActions;

/**
 * Aides utilisées par les tests fonctionnels générés. À placer dans la classe de base E2E de l'hôte
 * (celle qui étend Playwright\Symfony\Test\PlaywrightTestCase) :
 *
 *     abstract class E2ETest extends PlaywrightTestCase
 *     {
 *         use FunctionalTestHelpersTrait;
 *
 *         protected function loginAs(string $profile): void { ... }
 *     }
 *
 * Les attentes sont des scrutations d'un résultat précis (jamais « réseau inactif ») ; les assertions
 * PHPUnit restent dans les tests générés.
 */
trait FunctionalTestHelpersTrait
{
    private ?PlaywrightActions $functionalActions = null;

    /**
     * Connecte l'utilisateur correspondant au profil nommé dans le scénario (« trader », « admin »…).
     * Spécifique à chaque projet : formulaire de connexion, jeton WEBSSO en session, etc.
     */
    abstract protected function loginAs(string $profile): void;

    /**
     * Délai maximal des attentes et des actions Playwright, en millisecondes.
     */
    protected function functionalTimeoutMs(): int
    {
        return 5000;
    }

    /**
     * Ouvre un chemin et fixe le délai par défaut des actions.
     *
     * @return \Playwright\Page\PageInterface
     */
    protected function open(string $path)
    {
        $page = $this->visit($path);
        $page->setDefaultTimeout($this->functionalTimeoutMs());

        return $page;
    }

    /**
     * Nombre de lignes d'une table, lu par la connexion du test (donc dans la transaction DAMA).
     */
    protected function countRows(string $table): int
    {
        if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $table)) {
            throw new \InvalidArgumentException(sprintf('Nom de table invalide : "%s".', $table));
        }

        $connection = static::getContainer()->get('doctrine')->getConnection();

        return (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    /**
     * @param \Playwright\Locator\LocatorInterface $locator
     */
    protected function waitUntilVisible(object $locator): void
    {
        $this->functionalActions()->waitUntilVisible($locator);
    }

    /**
     * @param \Playwright\Locator\LocatorInterface $locator
     */
    protected function waitUntilHidden(object $locator): void
    {
        $this->functionalActions()->waitUntilHidden($locator);
    }

    /**
     * @param \Playwright\Page\PageInterface $page
     */
    protected function waitUntilUrl(object $page, string $expected): void
    {
        $this->functionalActions()->waitUntilUrl($page, $expected);
    }

    /**
     * @param \Playwright\Page\PageInterface $page
     */
    protected function waitUntilUrlContains(object $page, string $needle): void
    {
        $this->functionalActions()->waitUntilUrlContains($page, $needle);
    }

    /**
     * @param \Playwright\Page\PageInterface $page
     */
    protected function waitUntilTextVisible(object $page, string $text): void
    {
        $this->functionalActions()->waitUntilTextVisible($page, $text);
    }

    /**
     * @param \Playwright\Page\PageInterface $page
     */
    protected function waitUntilTextGone(object $page, string $text): void
    {
        $this->functionalActions()->waitUntilTextGone($page, $text);
    }

    /**
     * Texte visible de la page (icônes retirées, espaces réduits).
     *
     * @param \Playwright\Page\PageInterface $page
     */
    protected function visibleText(object $page): string
    {
        return $this->functionalActions()->visibleText($page);
    }

    /**
     * Chemin courant, avec sa requête si l'attente en contient une.
     *
     * @param \Playwright\Page\PageInterface $page
     */
    protected function currentPath(object $page, string $expected): string
    {
        return $this->functionalActions()->comparableUrl((string) $page->url(), str_contains($expected, '?'));
    }

    /**
     * @param \Playwright\Locator\LocatorInterface $locator
     */
    protected function selectValue(object $locator, string $value): void
    {
        $this->functionalActions()->selectValue($locator, $value);
    }

    /**
     * @param \Playwright\Locator\LocatorInterface $locator
     */
    protected function setChecked(object $locator, bool $checked): void
    {
        $this->functionalActions()->setChecked($locator, $checked);
    }

    private function functionalActions(): PlaywrightActions
    {
        return $this->functionalActions ??= new PlaywrightActions($this->functionalTimeoutMs());
    }
}
