# Spécification des Tests : `Mika\TestGeneratorBundle\Command\DatabaseResolverCommand`

* **Type de test** : Unit
* **Généré le** : 2026-09-12 11:41:18

---

## Dépendances à mocker

- `Mika\TestGeneratorBundle\Resolver\TestDatabaseResolver` (`$resolver`)
- `Symfony\Component\HttpKernel\KernelInterface` (`$kernel`)

---

## Méthode `execute()`

### Cas de tests

#### `testExecuteReturnsSuccessWhenPlatformIsNotPostgreSQL`
* **Type** : `HAPPY`
* **Description** : Vérifie que la commande s'arrête proprement avec un message de warning si la plateforme n'est pas PostgreSQL
* **Attentes Mocks** :
    - `KernelInterface::getProjectDir()` ➔ retourne `'/path/to/project'`
    - `TestDatabaseResolver::getDatabaseInfosFromKernel()` (avec `$kernelClass`, `'dev'`) ➔ retourne un `DatabaseInfosDto` avec `platform: 'mysql'`
    - `TestDatabaseResolver::getDatabaseInfosWithTestEnv()` ➔ retourne un `DatabaseInfosDto` quelconque
* **Retour attendu** : `0` (`Command::SUCCESS`)

#### `testExecuteSuccessWhenTestDbExistsAndNamesDiffer`
* **Type** : `HAPPY`
* **Description** : Vérifie le chemin nominal quand la base de test existe déjà et porte un nom différent de la base dev
* **Attentes Mocks** :
    - `KernelInterface::getProjectDir()` ➔ retourne `'/path/to/project'`
    - `TestDatabaseResolver::getDatabaseInfosFromKernel()` ➔ retourne un `DatabaseInfosDto` (`platform: 'postgresql'`, `dbExists: true`, `dbName: 'app_dev'`)
    - `TestDatabaseResolver::getDatabaseInfosWithTestEnv()` ➔ retourne un `DatabaseInfosDto` (`dbExists: true`, `dbName: 'app_test'`)
    - `TestDatabaseResolver::checkDatabaseNamesAreDifferent()` ➔ retourne `['success' => 0, 'message' => 'Les noms sont différents']`
* **Retour attendu** : `0` (`Command::SUCCESS`)

#### `testExecuteFailureWhenTestDbExistsAndNamesAreSame`
* **Type** : `ERROR`
* **Description** : Vérifie l'échec de la commande lorsque la base de test porte le même nom que la base dev
* **Attentes Mocks** :
    - `KernelInterface::getProjectDir()` ➔ retourne `'/path/to/project'`
    - `TestDatabaseResolver::getDatabaseInfosFromKernel()` ➔ retourne un `DatabaseInfosDto` (`platform: 'postgresql'`, `dbExists: true`, `dbName: 'app_dev'`)
    - `TestDatabaseResolver::getDatabaseInfosWithTestEnv()` ➔ retourne un `DatabaseInfosDto` (`dbExists: true`, `dbName: 'app_dev'`)
    - `TestDatabaseResolver::checkDatabaseNamesAreDifferent()` ➔ retourne `['success' => 1, 'message' => 'Les noms sont identiques']`
* **Retour attendu** : `1` (`Command::FAILURE`)

#### `testExecuteReturnsFailureWhenEnvFileCreationFails`
* **Type** : `ERROR`
* **Description** : Vérifie l'échec quand la base de test n'existe pas, que le fichier `.env.test.local` est absent et que sa création échoue
* **Attentes Mocks** :
    - `KernelInterface::getProjectDir()` ➔ retourne un dossier temporaire ou mocké où `.env.test.local` n'existe pas
    - `TestDatabaseResolver::getDatabaseInfosFromKernel()` ➔ retourne un `DatabaseInfosDto` (`platform: 'postgresql'`, `dbExists: true`)
    - `TestDatabaseResolver::getDatabaseInfosWithTestEnv()` ➔ retourne un `DatabaseInfosDto` (`dbExists: false`, `dbName: 'app_test'`, `hasSuffix: false`)
    - `TestDatabaseResolver::createOrUpdateTestEnvFileAndDatabaseUrl()` ➔ retourne `1` (`Command::FAILURE`)
* **Retour attendu** : `1` (`Command::FAILURE`)

#### `testExecuteReturnsSuccessWhenUserCancelsDatabaseCreation`
* **Type** : `HAPPY`
* **Description** : Vérifie que l'annulation par l'utilisateur lors de la confirmation interactive retourne un succès sans exécuter la suite
* **Attentes Mocks** :
    - `KernelInterface::getProjectDir()` ➔ retourne un dossier temporaire contenant un fichier `.env.test.local` (ou simulé via vfsStream/tmp)
    - `TestDatabaseResolver::getDatabaseInfosFromKernel()` (appel DEV) ➔ retourne un `DatabaseInfosDto` (`platform: 'postgresql'`, `dbExists: true`)
    - `TestDatabaseResolver::getDatabaseInfosWithTestEnv()` ➔ retourne un `DatabaseInfosDto` (`dbExists: false`, `dbName: 'app_test'`)
    - `TestDatabaseResolver::getDatabaseInfosFromKernel()` (appel TEST réévalué) ➔ retourne un `DatabaseInfosDto` (`dbName: 'app_test'`)
    - Entrée utilisateur interactive : Répond `false` (non) à la confirmation `$io->confirm()`
* **Retour attendu** : `0` (`Command::SUCCESS`)

#### `testExecuteFailureWhenDatabaseCreationFailed`
* **Type** : `ERROR`
* **Description** : Vérifie la gestion d'erreur lors de l'échec de la commande `database:create` via le sous-processus Doctrine
* **Attentes Mocks** :
    - `KernelInterface::getProjectDir()` ➔ retourne un dossier temporaire avec `.env.test.local`
    - `TestDatabaseResolver::getDatabaseInfosFromKernel()` (appel DEV) ➔ retourne un `DatabaseInfosDto` (`platform: 'postgresql'`, `dbExists: true`)
    - `TestDatabaseResolver::getDatabaseInfosWithTestEnv()` ➔ retourne un `DatabaseInfosDto` (`dbExists: false`)
    - `TestDatabaseResolver::getDatabaseInfosFromKernel()` (appel TEST) ➔ retourne un `DatabaseInfosDto` (`dbName: 'app_test'`)
    - Entrée utilisateur interactive : Répond `true` (oui)
    - `TestDatabaseResolver::handleDoctrineCommands()` (commande `database:create`) ➔ retourne un `Process` mocké avec `isSuccessful() = false` et `getErrorOutput() = 'Fatal Error'`
* **Retour attendu** : `1` (`Command::FAILURE`)

#### `testExecuteFailureWhenSynchronisationFailed`
* **Type** : `ERROR`
* **Description** : Vérifie l'échec lorsque la création de la base réussit mais que la synchronisation du schéma (migrations/schema) échoue
* **Attentes Mocks** :
    - `KernelInterface::getProjectDir()` ➔ retourne un dossier temporaire avec `.env.test.local`
    - `TestDatabaseResolver::getDatabaseInfosFromKernel()` (appel DEV) ➔ retourne un `DatabaseInfosDto` (`platform: 'postgresql'`, `dbExists: true`)
    - `TestDatabaseResolver::getDatabaseInfosWithTestEnv()` ➔ retourne un `DatabaseInfosDto` (`dbExists: false`)
    - `TestDatabaseResolver::getDatabaseInfosFromKernel()` (appel TEST) ➔ retourne un `DatabaseInfosDto` (`dbName: 'app_test'`)
    - Entrée utilisateur interactive : Répond `true` (oui)
    - `TestDatabaseResolver::handleDoctrineCommands()` ➔ retourne un `Process` mocké avec `isSuccessful() = true`
    - `TestDatabaseResolver::handleDoctrineSynchronisation()` ➔ retourne `false`
* **Retour attendu** : `1` (`Command::FAILURE`)

#### `testExecuteNominalFullSuccess`
* **Type** : `HAPPY`
* **Description** : Vérifie le déroulement complet d'une création et synchronisation réussie de la base de test
* **Attentes Mocks** :
    - `KernelInterface::getProjectDir()` ➔ retourne un dossier temporaire avec `.env.test.local`
    - `TestDatabaseResolver::getDatabaseInfosFromKernel()` (appel DEV) ➔ retourne un `DatabaseInfosDto` (`platform: 'postgresql'`, `dbExists: true`)
    - `TestDatabaseResolver::getDatabaseInfosWithTestEnv()` ➔ retourne un `DatabaseInfosDto` (`dbExists: false`)
    - `TestDatabaseResolver::getDatabaseInfosFromKernel()` (appel TEST) ➔ retourne un `DatabaseInfosDto` (`dbName: 'app_test'`)
    - Entrée utilisateur interactive : Répond `true` (oui)
    - `TestDatabaseResolver::handleDoctrineCommands()` ➔ retourne un `Process` mocké avec `isSuccessful() = true`
    - `TestDatabaseResolver::handleDoctrineSynchronisation()` ➔ retourne `true`
* **Retour attendu** : `0` (`Command::SUCCESS`)

---

## Méthode `displayMessage()`

### Cas de tests

#### `testDisplayMessageOutputsTableAndFormattedType`
* **Type** : `HAPPY`
* **Description** : Vérifie que le tableau et le bon style de message (`warning`, `error`, `success`) sont correctement transmis à l'instance de `SymfonyStyle`
* **Attentes Mocks** :
    - `SymfonyStyle::table()` ➔ appelé avec l'en-tête `['Paramètre', 'Valeur']` et les données extraites des deux `DatabaseInfosDto`
    - `SymfonyStyle::success()` (ou `warning`/`error`) ➔ appelé avec le message passé en argument
* **Retour attendu** : `void`
