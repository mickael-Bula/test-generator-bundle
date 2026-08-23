<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Renderer;

use Mika\TestGeneratorBundle\Dto\SpecResultDto;

class SpecMarkdownRenderer
{
    /**
     * Convertit le tableau de données de spécification JSON en un document Markdown structuré.
     *
     * @param SpecResultDto $specDto Le tableau issu du JSON parsé par SpecGeneratorAgent
     *
     * @throws \JsonException
     */
    public function render(SpecResultDto $specDto): string
    {
        $specData = $specDto->toArray();

        $targetClass = $specData['targetClass'] ?? 'ClasseInconnue';
        $testType = $specData['testType'] ?? 'Unit';
        $dependencies = $specData['dependenciesToMock'] ?? [];
        $methods = $specData['methods'] ?? [];

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
                $class = $dep['class'] ?? 'FQCN\\Inconnu';
                $prop = $dep['propertyName'] ?? 'propriete';
                $md[] = sprintf('- `%s` (`$%s`)', $class, $prop);
            }
        }
        $md[] = '';
        $md[] = '---';
        $md[] = '';

        // 3. Spécifications par méthode
        foreach ($methods as $method) {
            $methodName = $method['name'] ?? 'methodeAnonyme';
            $dataProviders = $method['dataProviders'] ?? [];
            $testCases = $method['testCases'] ?? [];

            $md[] = sprintf('## Méthode `%s()`', $methodName);
            $md[] = '';

            // A. Rendu des Data Providers s'il y en a
            if (!empty($dataProviders)) {
                foreach ($dataProviders as $provider) {
                    $md[] = sprintf('### Data Provider : `%s`', $provider['providerName'] ?? 'provideData');
                    if (!empty($provider['description'])) {
                        $md[] = sprintf('> %s', $provider['description']);
                    }
                    $md[] = '';

                    $keys = $provider['dataSetKeys'] ?? [];
                    $dataSets = $provider['dataSets'] ?? [];

                    if (!empty($keys) && !empty($dataSets)) {
                        // Génération de la table Markdown
                        $headers = array_merge(['Label / Description'], array_map(static fn ($k) => sprintf('`$%s`', $k), $keys));
                        $md[] = '| ' . implode(' | ', $headers) . ' |';

                        $separators = array_fill(0, count($headers), ':---');
                        $md[] = '| ' . implode(' | ', $separators) . ' |';

                        foreach ($dataSets as $dataSet) {
                            $row = [sprintf('**%s**', $dataSet['label'] ?? 'Cas')];
                            $providedValues = $dataSet['providedValues'] ?? [];

                            foreach ($keys as $key) {
                                $val = $providedValues[$key] ?? 'null';
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
                    $title = $tc['title'] ?? $tc['id'] ?? 'testAnonyme';
                    $type = strtoupper($tc['type'] ?? 'NOMINAL');
                    $description = $tc['description'] ?? '';
                    $usesDp = $tc['usesDataProvider'] ?? false;
                    $dpName = $tc['dataProviderName'] ?? null;

                    $md[] = sprintf('#### `%s`', $title);
                    $md[] = sprintf('* **Type** : `%s`', $type);
                    if ($description) {
                        $md[] = sprintf('* **Description** : %s', $description);
                    }
                    if ($usesDp && $dpName) {
                        $md[] = sprintf('* **Data Provider associé** : `%s`', $dpName);
                    }

                    // Entrées isolées
                    $inputs = $tc['inputs'] ?? [];
                    if (!$usesDp && !empty($inputs)) {
                        $formattedInputs = [];
                        foreach ($inputs as $k => $v) {
                            $formattedInputs[] = sprintf('`$%s` = `%s`', $k, $this->formatValue($v));
                        }
                        $md[] = sprintf('* **Entrées** : %s', implode(', ', $formattedInputs));
                    }

                    // Attentes sur Mocks
                    $mockExpectations = $tc['mockExpectations'] ?? [];
                    if (!empty($mockExpectations)) {
                        $md[] = '* **Attentes Mocks** :';
                        foreach ($mockExpectations as $mock) {
                            $depClass = $mock['dependency'] ?? 'Class';
                            $mName = $mock['method'] ?? 'method';
                            $willRet = isset($mock['willReturns'])
                                ? sprintf(' ➔ retourne `%s`', $this->formatValue($mock['willReturns']))
                                : '';

                            $willThrow = !empty($mock['willThrow'])
                                ? sprintf(' ➔ lève `%s`', $mock['willThrow'])
                                : '';

                            $md[] = sprintf('  - `%s::%s()`%s%s', $depClass, $mName, $willRet, $willThrow);
                        }
                    }

                    // Comportement attendu
                    $expected = $tc['expectedBehavior'] ?? [];
                    if (!empty($expected)) {
                        if (!empty($expected['throwsException'])) {
                            $md[] = sprintf('* **Exception attendue** : `%s`', $expected['throwsException']);
                            if (!empty($expected['exceptionMessage'])) {
                                $md[] = sprintf('  - Message : *"%s"*', $expected['exceptionMessage']);
                            }
                        } elseif (array_key_exists('returnValue', $expected) && null !== $expected['returnValue']) {
                            $md[] = sprintf('* **Retour attendu** : `%s`', $this->formatValue($expected['returnValue']));
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
