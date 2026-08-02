# Skill: Tests de Commandes Symfony

Quand tu génères un test pour une classe qui hérite de `Symfony\Component\Console\Command\Command` :
1. **CommandTester :** Utilise EXCLUSIVEMENT `Symfony\Component\Console\Tester\CommandTester` pour exécuter la commande. 
N'utilise JAMAIS `ReflectionMethod` ou de mocks manuels sur `InputInterface`/`OutputInterface`.
2. **Assertions :** Vérifie le code de retour (`Command::SUCCESS`) ET le contenu de la console avec `$commandTester->getDisplay()`.
3. **Nettoyage :** Si la commande manipule des fichiers temporaires, 
utilise `Symfony\Component\Filesystem\Filesystem` pour les vérifications et le nettoyage dans `tearDown()`.
