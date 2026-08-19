# SKILL : ERREURS DE CHEMINS, DE FICHIERS ET FAILURES D'ASSERTION (IOException / Path Errors / Fails)

Le rapport d'erreur mentionne une `IOException`, un problème de création de répertoire ou un échec d'assertion lié à l'existence d'un fichier/dossier.

CONSIGNES DE CORRECTION :
1. **Isolation temporaire** :
    - Utilise un dossier temporaire propre dans `setUp()` :
      ```php
      $this->tempDir = sys_get_temp_dir() . '/test_' . uniqid();
      ```
    - Nettoie systématiquement ce dossier dans `tearDown()` :
      ```php
      (new Filesystem())->remove($this->tempDir);
      ```
2. **Chemins relatifs vs absolus** :
    - Pour les arguments de méthodes représentant des sous-dossiers de sortie (ex : `$outputDir`), utilise TOUJOURS des noms relatifs simples (ex : `'specs'` ou `'var/specs'`).
    - NE CONCATÈNE JAMAIS deux chemins absolus (ex : JAMAIS `$this->tempDir . '/C:/Users/...'`).
3. **Préparation des fichiers pour assertions** :
    - Si le test vérifie l'existence d'un fichier (ex : `hasSpec()`), tu DOIS créer physiquement la structure de répertoires et le fichier de test dans `$this->tempDir` AVANT de jouer l'assertion.