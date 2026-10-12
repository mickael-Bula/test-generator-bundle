<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Resolver;

use Composer\InstalledVersions;
use Symfony\Component\Console\Style\SymfonyStyle;

class DamaTestBundleResolver
{
    private const EXTENSION_CLASS = 'DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension';
    private const PACKAGE_NAME = 'dama/doctrine-test-bundle';

    public function checkDamaBundle(SymfonyStyle $io, string $projectDir): bool
    {
        // 1. Vérification robuste de la présence du paquet via Composer API ou class_exists sous forme de string
        $isInstalled = InstalledVersions::isInstalled(self::PACKAGE_NAME)
            || class_exists('DAMA\DoctrineTestBundle\DAMADoctrineTestBundle');

        if (!$isInstalled) {
            $io->warning('Le bundle "dama/doctrine-test-bundle" n\'est pas installé sur le projet hôte.');
            $io->note('Exécutez : composer require --dev dama/doctrine-test-bundle');

            return false;
        }

        // 2. Recherche du fichier de configuration PHPUnit
        $phpunitPath = null;
        foreach (['phpunit.xml', 'phpunit.xml.dist', 'phpunit.dist.xml'] as $file) {
            $filePath = $projectDir . '/' . $file;
            if (file_exists($filePath)) {
                $phpunitPath = $filePath;
                break;
            }
        }

        if (null === $phpunitPath) {
            $io->warning('Aucun fichier de configuration PHPUnit n\'a été trouvé pour vérifier la configuration DAMA.');

            return false;
        }

        // 3. Analyse XML sécurisée (ignore les commentaires)
        if (!$this->isExtensionEnabledInXml($phpunitPath)) {
            $io->warning(sprintf(
                'Le bundle DAMA est installé mais l\'extension n\'est pas activée dans "%s".',
                basename($phpunitPath)
            ));
            $io->note("Ajoutez la ligne suivante dans la section <extensions> de votre phpunit.xml :\n" .
                '<bootstrap class="DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension"/>'
            );

            return false;
        }

        $io->info('Le bundle dama/doctrine-test-bundle est présent et correctement configuré.');

        return true;
    }

    private function isExtensionEnabledInXml(string $xmlPath): bool
    {
        $useErrors = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($xmlPath);
        libxml_use_internal_errors($useErrors);

        if (false === $xml) {
            return false;
        }

        // Recherche des nœuds extension ou bootstrap contenant la classe DAMA
        foreach ($xml->xpath('//extensions/extension | //extensions/bootstrap') as $node) {
            if ((string) $node['class'] === self::EXTENSION_CLASS) {
                return true;
            }
        }

        return false;
    }
}