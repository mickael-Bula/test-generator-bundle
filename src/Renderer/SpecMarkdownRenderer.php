<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Renderer;

use Mika\TestGeneratorBundle\Dto\SpecResultDto;

class SpecMarkdownRenderer
{
    /**
     * Convertit l'objet SpecResultDto en un document Markdown structuré.
     *
     * @throws \JsonException
     */
    public function render(SpecResultDto $specDto): string
    {
        $targetClass = trim($specDto->getTargetClass()) ?: 'ClasseInconnue';
        $testType = $specDto->getTestType();
        $dependencies = $specDto->getDependenciesToMock();
        $methods = $specDto->getMethods();

        $md = [];

        // 1. En-tête principal
        $md[] = sprintf('# Spécification des Tests : `%s`', $targetClass);
        $md[] = '';
        $md[] = sprintf('* **Type de test** : %s', $testType);
        $md[] = sprintf('* **Généré le** : %s', date('Y-m-d H:i:s'));
        $md[] = '';
        $md[] = '---';
        $md[] = '';

        // 2. Dépendances à mocker
        $md[] = '## Dépendances à mocker';
        $md[] = '';
        if (empty($dependencies)) {
            $md[] = '_Aucune dépendance externe à mocker._';
        } else {
            foreach ($dependencies as $dep) {
                $class = trim($dep->getClass()) ?: 'FQCN\\Inconnu';
                $prop = trim($dep->getPropertyName()) ?: 'propriete';
                $md[] = sprintf('- `%s` (`$%s`)', $class, $prop);
            }
        }
        $md[] = '';
        $md[] = '---';
        $md[] = '';

        // 3. Spécifications par méthode
        foreach ($methods as $method) {
            $methodName = trim($method->getName()) ?: 'methodeAnonyme';
            $dataProviders = $method->getDataProviders();
            $testCases = $method->getTestCases();

            $md[] = sprintf('## Méthode `%s()`', $methodName);
            $md[] = '';

            // A. Rendu des Data Providers s'il y en a
            if (!empty($dataProviders)) {
                foreach ($dataProviders as $provider) {
                    $providerName = trim($provider->getProviderName()) ?: 'provideData';
                    $md[] = sprintf('### Data Provider : `%s`', $providerName);
                    if ('' !== trim($provider->getDescription())) {
                        $md[] = sprintf('> %s', $provider->getDescription());
                    }
                    $md[] = '';

                    $keys = $provider->getDataSetKeys();
                    $dataSets = $provider->getDataSets();

                    if (!empty($keys) && !empty($dataSets)) {
                        // Génération de la table Markdown
                        $headers = array_merge(
                            ['Label / Description'],
                            array_map(static fn (string $k) => sprintf('`$%s`', $k), $keys)
                        );
                        $md[] = '| ' . implode(' | ', $headers) . ' |';

                        $separators = array_fill(0, count($headers), ':---');
                        $md[] = '| ' . implode(' | ', $separators) . ' |';

                        foreach ($dataSets as $dataSet) {
                            $row = [sprintf('**%s**', trim($dataSet->getLabel()) ?: 'Cas')];
                            $providedValues = $dataSet->getProvidedValues();

                            foreach ($keys as $key) {
                                $val = $providedValues[$key] ?? null;
                                $row[] = sprintf('`%s`', $this->formatValue($val));
                            }

                            $md[] = '| ' . implode(' | ', $row) . ' |';
                        }
                        $md[] = '';
                    }
                }
            }

            // B. Rendu des Cas de Tests Isolés
            if (!empty($testCases)) {
                $md[] = '### Cas de tests';
                $md[] = '';

                foreach ($testCases as $tc) {
                    $title = trim($tc->getTitle()) ?: 'testAnonyme';
                    $type = strtoupper($tc->getType());
                    $description = $tc->getDescription();
                    $usesDp = $tc->isUsesDataProvider();
                    $dpName = $tc->getDataProviderName();

                    $md[] = sprintf('#### `%s`', $title);
                    $md[] = sprintf('* **Type** : `%s`', $type);
                    if ('' !== trim($description)) {
                        $md[] = sprintf('* **Description** : %s', $description);
                    }
                    if ($usesDp && null !== $dpName && '' !== trim($dpName)) {
                        $md[] = sprintf('* **Data Provider associé** : `%s`', $dpName);
                    }

                    // Entrées isolées
                    $inputs = $tc->getInputs();
                    if (!$usesDp && !empty($inputs)) {
                        $formattedInputs = [];
                        foreach ($inputs as $k => $v) {
                            $formattedInputs[] = sprintf('`$%s` = `%s`', $k, $this->formatValue($v));
                        }
                        $md[] = sprintf('* **Entrées** : %s', implode(', ', $formattedInputs));
                    }

                    // Attentes sur les Mocks
                    $mockExpectations = $tc->getMockExpectations();
                    if (!empty($mockExpectations)) {
                        $md[] = '* **Attentes Mocks** :';
                        foreach ($mockExpectations as $mock) {
                            $depClass = trim($mock->getDependency()) ?: 'Class';
                            $mName = trim($mock->getMethod()) ?: 'method';

                            $willRet = null !== $mock->getWillReturns()
                                ? sprintf(' ➔ retourne `%s`', $this->formatValue($mock->getWillReturns()))
                                : '';

                            $willThrow = null !== $mock->getWillThrow() && '' !== trim($mock->getWillThrow())
                                ? sprintf(' ➔ lève `%s`', $mock->getWillThrow())
                                : '';

                            $md[] = sprintf('  - `%s::%s()`%s%s', $depClass, $mName, $willRet, $willThrow);
                        }
                    }

                    // Comportement attendu
                    $expected = $tc->getExpectedBehavior();
                    if (null !== $expected) {
                        if (null !== $expected->getThrowsException() && '' !== trim($expected->getThrowsException())) {
                            $md[] = sprintf('* **Exception attendue** : `%s`', $expected->getThrowsException());
                            if (null !== $expected->getExceptionMessage() && '' !== trim($expected->getExceptionMessage())) {
                                $md[] = sprintf('  - Message : *"%s"*', $expected->getExceptionMessage());
                            }
                        } elseif (null !== $expected->getReturnValue()) {
                            $md[] = sprintf('* **Retour attendu** : `%s`', $this->formatValue($expected->getReturnValue()));
                        }
                    }

                    $md[] = '';
                }
            }

            $md[] = '---';
            $md[] = '';
        }

        return implode("\n", $md);
    }

    /**
     * Formate n'importe quelle valeur PHP (array, bool, null, scalar) pour un affichage lisible dans le tableau.
     *
     * @throws \JsonException
     */
    private function formatValue(mixed $value): string
    {
        if (null === $value) {
            return 'null';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[...]';
        }

        return (string) $value;
    }
}
