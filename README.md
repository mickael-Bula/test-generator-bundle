# Création du Bundle

L'organisation d'un bundle Symfony suit une organisation précise

## Procédure de création

### Étape 1 : Le composer.json du Bundle

Créer un dossier TestGeneratorBundle et s'y déplacer :

```bash
mkdir TestGeneratorBundle && cd TestGeneratorBundle
```

Ajouter à la racine un fichier `composer.json` avec ce contenu :

```json
{
    "name": "mika/test-generator-bundle",
    "version": "0.0.1-dev",
    "description": "Bundle Symfony pour générer automatiquement des tests PHPUnit via LLM",
    "type": "symfony-bundle",
    "license": "MIT",
    "autoload": {
        "psr-4": {
            "Mika\\TestGeneratorBundle\\": "src/"
        }
    },
    "require": {
        "php": ">=8.2",
        "nikic/php-parser": "^5.0",
        "symfony/framework-bundle": "^6.4|^7.0",
        "symfony/console": "^6.4|^7.0",
        "symfony/http-client": "^6.4|^7.0",
        "symfony/ai-generic-platform": "^0.12.0",
        "symfony/ai-open-router-platform": "^0.12.0",
        "symfony/ai-ollama-platform": "^0.12.0",
        "symfony/process": "^7.4",
        "symfony/ai-platform": "^0.12.0",
        "symfony/ai-open-ai-platform": "^0.12.0",
        "symfony/ai-anthropic-platform": "^0.12.0",
        "symfony/ai-gemini-platform": "^0.12.0"
    },
    "extra": {
        "symfony": {
            "allow-contrib": false
        }
    }
}
```

Installer les librairies avec la commande :

```bash
composer install
```

### Étape 2 : La classe principale TestGeneratorBundle

Selon les normes PSR-4 et les conventions Symfony, la classe principale du bundle TestGeneratorBundle.php se place directement à la racine du dossier src/, exactement comme suit :

```Plaintext
test-generator-bundle/
├── config/
│   └── services.php
├── docs/
│   └── specifications.ms
├── Resources/
│   ├── skill/
│   │   ├── dto_test.md
│   │   ├── filsystem_test.md
│   │   ├── php-parser-v5.md
│   │   └── symfony_command.md
│   └── spec-templates/
│       ├── test_spec_class_template.md
│       └── test_spec_template.md
├── src/
│   ├── Attribute/
│   │   └── AsTestPromptBuilder.php
│   ├── Command/
│   │   ├── GenerateTestCommand.php
│   │   └── MakeTestSpecCommand.php
│   ├── DependencyInjection/
│   │   ├── Configuration.php
│   │   └── TestGeneratorExtension.php
│   ├── Dto/
│   │   └── GeneratedTestResult.php
│   ├── Enum/
|   │   └── TestType.php
│   ├── Exception/
│   │   ├── TestCorrectionException.php
|   │   └── TestGenerationException.php
│   ├── Llm/
│   │   ├── LlmClientFactory.php
│   │   ├── LlmClientInterface.php
|   │   └── SymfonyAiClient.php
│   ├── ModelCatalog/
│   │   └── PermissiveModelCatalog.php
│   ├── PromptBuilder/
│   │   ├── FunctionalPromptBuilder.php
│   │   ├── TestPromptBuilderInterface.php
|   │   └── UnitTestPromptBuilder.php
│   ├── RepoMap/
│   │   ├── CacheRepoMapBuilder.php
│   │   ├── RepoMapBuilder.php
|   │   └── RepoMapVisitor.php
│   ├── Resolver/
│   │   ├── ClassResolver.php
│   │   ├── SkillResolver.php
|   │   └── SpecResolver.php
│   ├── Service/
│   │   ├── AiPlatformFactory.php
│   │   ├── ClassCodeResolver.php
│   │   ├── PhpUnitTestRunner.php
│   │   └── TestGenerator.php
│   └── TestGeneratorBundle.php
├── composer.json
└── README.md
```

Code de `src/TestGeneratorBundle.php` :

```php
<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class TestGeneratorBundle extends Bundle
{
    /**
     * Retourne le chemin racine du bundle (ici le dossier parent de src/).
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
```

### Étape 3 : L'Extension et la Configuration de dépendances

Grâce aux récents composants AbstractBundle de Symfony (depuis la v6.1), le chargement des services est devenu extrêmement simple.

#### 1. Définition des services (config/services.php) :

```php
<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Mika\TestGeneratorBundle\Attribute\AsTestPromptBuilder;
use Mika\TestGeneratorBundle\ModelCatalog\PermissiveModelCatalog;
use Symfony\Component\DependencyInjection\ChildDefinition;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Chargement automatique des classes du bundle
    $services->load('Mika\\TestGeneratorBundle\\', '../src/*')
        ->exclude('../src/{DependencyInjection,Dto,Enum,Exception,Resources,TestGeneratorBundle.php}');

    // Enregistrement explicite du catalogue permissif
    $services->set(PermissiveModelCatalog::class);

    // Attribue automatiquement le tag 'mika_test_generator.prompt_builder'
    // à n'importe quelle classe annotée avec #[AsTestPromptBuilder]
    $container->services()
        ->registerAttributeForAutoconfiguration(
            AsTestPromptBuilder::class,
            static function (ChildDefinition $definition, AsTestPromptBuilder $attribute): void {
                $definition->addTag('mika_test_generator.prompt_builder', [
                    'type' => $attribute->type,
                ]);
            }
        );
};
```

#### 2. La classe Configuration.php (src/DependencyInjection/Configuration.php)

Elle permet de rendre le bundle configurable par l'application hôte (par exemple dans un fichier config/packages/test_generator.yaml).

Cette classe définit le schéma des options autorisées (fournisseur par défaut, modèle par défaut, chemins des templates/skills, etc.).

```php
<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('test_generator');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('default_provider')
                    ->defaultValue('%env(default::LLM_PROVIDER)%')
                ->end()
                ->scalarNode('default_model')
                    ->defaultValue('%env(default::LLM_MODEL)%')
                ->end()
                ->scalarNode('project_dir')
                    ->defaultValue('%kernel.project_dir%')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
```

#### 3. L'Extension (src/DependencyInjection/TestGeneratorExtension.php) :

la liaison dans `TestGeneratorExtension.php` pour permettre à toute application hôte d'installer le bundle et de personnaliser ses paramètres proprement :

```php
<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class TestGeneratorExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.php');

        // Injection des paramètres si nécessaire dans le conteneur
        $container->setParameter('test_generator.project_dir', $config['project_dir']);
    }
}
```

#### Étape 4 : Isoler la classe PermissiveModelCatalog

```php
<?php

namespace Mika\TestGeneratorBundle\ModelCatalog;

use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;

final readonly class PermissiveModelCatalog implements ModelCatalogInterface
{
    public function supports(): bool
    {
        return true;
    }

    public function getModel(string $modelName): CompletionsModel
    {
        return new CompletionsModel($modelName);
    }

    public function getModels(): array
    {
        return [];
    }
}
```

## Importer les classes dans le Bundle

Récupérer les classes du projet **test_generator** et les déposer sous `src`.

## Lier le bundle à une application hôte (Développement local)

voici la procédure pas à pas pour lier le bundle en local avec les projets Symfony via un path repository Composer. C'est la méthode officielle qui permet de travailler sur le bundle et de tester les modifications en temps réel, sans avoir à publier sur Git ou Packagist.

### 1. Structurer l'arborescence des projets

Il faut placer le dossier du bundle au même niveau que le projet Symfony hôte (ou dans un sous-dossier lib/ ou bundles/ à l'intérieur du projet) :

```Plaintext
mon-workspace/
├── mon-projet-symfony/       <-- Le projet hôte
│   ├── composer.json
│   └── ...
└── test-generator-bundle/   <-- Le code du bundle
    ├── composer.json
    ├── src/
    └── ...
```

### 2. Configurer le composer.json du projet Symfony hôte

Dans le fichier composer.json du projet Symfony hôte, ajouter le dépôt local dans la section repositories, puis demander la dépendance vers le bundle :

```json
{
    "name": "mon-organisation/mon-projet-symfony",
    "type": "project",
    "require": {
        "php": ">=8.2",
        "symfony/console": "^6.4|^7.0",
        "symfony/framework-bundle": "^6.4|^7.0",
        
        "mika/test-generator-bundle": "@dev"
    },
    "repositories": [
        {
            "type": "path",
            "url": "../test-generator-bundle",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

>NOTE : Note : L'option "symlink": true crée un lien symbolique dans le dossier vendor/mika/test-generator-bundle. Tout changement effectué dans le code du bundle sera immédiatement répercuté dans ton projet Symfony sans réinstaller quoi que ce soit.

### 3. Installer le bundle via Composer

Dans le terminal du projet Symfony hôte, lancer l'installation :

```bash
composer update mika/test-generator-bundle
```

Composer va détecter le répertoire local et lier le bundle.

### 4. Activer le bundle dans config/bundles.php

Si Symfony Flex est utilisé sans recette automatique pour le bundle personnalisé, il faut le déclarer manuellement dans config/bundles.php du projet hôte :

```php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    // ...
    YourVendor\TestGeneratorBundle\TestGeneratorBundle::class => ['all' => true],
];
```

### 5. Vérifier la bonne prise en compte

Lancer la commande console dans le projet Symfony hôte pour vérifier que la commande CLI du bundle est bien exposée :

```bash
php bin/console list app
# ou avec le nom de la commande
php bin/console app:generate-test --help
```
+
Si la commande apparaît, la tuyauterie et l'injection de dépendances du bundle fonctionnent. Il est alors possible de modifier le code dans test-generator-bundle/src/ et retester l'exécution directement !

## Gestion du fichier .gitignore

**Rappel important** : Pour un bundle/bibliothèque, ignorer composer.lock est la bonne pratique. Cela garantit que les tests s'exécuteront avec les versions les plus récentes des dépendances lors des mises à jour.
