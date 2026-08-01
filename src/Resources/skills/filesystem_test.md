# Skill: Isolation du Système de Fichiers

Quand la classe à tester manipule des fichiers (`file_put_contents`, `mkdir`, `Finder`, etc.) :
1. **Dossier temporaire :** Crée un dossier dédié dans `setUp()` avec `sys_get_temp_dir() . '/test_' . bin2hex(random_bytes(8))`.
2. **Nettoyage :** Supprime intégralement ce dossier temporaire dans `tearDown()` en utilisant `Symfony\Component\Filesystem\Filesystem::remove()`.
