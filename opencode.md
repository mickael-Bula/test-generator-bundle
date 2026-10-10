## Installation d'OpenCode

Installation dans WSL :

```bash
curl -fsSL https://opencode.ai/install | bash
ip route | grep default # récupère l'adresse IP de l'hôte, par ex : 172.30.48.1
curl http://$(ip route | awk '/default/ {print $3}'):11434/api/tags # retourne les modèles disponibles

mkdir -p ~/.config/opencode # création du répertoire de configuration d'OpenCode
nano ~/.config/opencode/opencode.json
```

Ajouter ce JSON :

```json
{
  "$schema": "https://opencode.ai/config.json",
  "providers": {
    "ollama": {
      "settings": {
        "baseURL": "http://172.30.48.1:11434/v1"
      }
    }
  }
}
```

### Relancer le terminal et lancer OpenCOde dans le projet

```bash
cd ~/projects/webtrader_assetmapper
opencode
```

Puis dans OpenCode : `/models`

Doit afficher : 

`ollama/qwen3-coder:30b
ollama/qwen2.5-coder:14b
`

## Commandes

Pour sélectionner, utiliser MAJ + souris, puis CTRL + C. Coller avec CTRL + V
