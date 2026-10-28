## Depuis WSL, appeler Ollama sous Windows

Sous Windows, Ollama doit être configuré pour écouter sur tous les ports :

```powershell

```

```env
OLLAMA_HOST="http://host.docker.internal:11434" # Résout l'IP de Windows depuis WSL
```

Si `host.docker.internal` ne résout pas l'IP de Windows, utilisez le DNS par défaut de WSL :

```bash
OLLAMA_HOST=http://$(ip route list default | awk '{print $3}'):11434
```

Il est aussi possible de récupérer l'IP dde Windows :

```PowerShell
ipconfig # Adresse IPv4 . . . : 192.168.1.87
```

Si l'IP a changé, il faut la redéclarer dans le fichier `.env` sous la clé `OLLAMA_HOST`.

## Test de validation dans WSL

```bash
curl http://host.docker.internal:11434/api/tags
```

--- TODOs : À déplacer

## Générer les tests sans Xdebug

```bash
php -dxdebug.mode=off bin/console llm:generate:spec "App\Service\PortfolioService"
```

## Ajout d'un skill

Pour éviter que le LLM ne génère à nouveau withConsecutive() dans le futur, ajoute la consigne suivante dans le System Prompt de ton agent de génération de tests :

```markdown
CONTRAINTE PHPUNIT 11 STRICTE :
1. La méthode `->withConsecutive()` est STRICTEMENT INTERDITE (supprimée depuis PHPUnit 10).
2. Pour mocker plusieurs appels successifs d'une même méthode avec des arguments/retours différents, utilise `->willReturnCallback(function($arg) { ... }`).
```
