# SKILL : INTERDICTION ABSOLUE DES NOMS COURTS ET OBLIGATION DES FQCN (UnknownTypeException)

Le rapport d'erreur indique une `UnknownTypeException` car une classe, un repository, un service, une entité, 
un enum ou une interface de l'application a été instancié ou typé avec un nom court non qualifié
(ex : "Class or interface '...' does not exist").

CONSIGNES DE CORRECTION (RÈGLE ABSOLUE) :
1. **Interdiction totale des classes nues** : Il est **strictement interdit** d'utiliser un nom de classe ou d'interface de l'application sans son espace de noms complet.
2. **Utilisation systématique des FQCN (`\Namespace\...`)** :
    - Pour **TOUTES** les utilisations dans le fichier de test (propriétés typées, arguments de `createMock()`, instanciations `new`, énumérations), 
      tu dois obligatoirement préfixer par le chemin complet avec un antislash initial.
    - ❌ **INTERDIT** : `private MonRepository $repo;`, `$this->createMock(MonService::class)`, `new MonEntite()`, `MonEnum::VALEUR`
    - ✅ **AUTORISÉ** : `private \Namespace\De\Lapplication\Repository\MonRepository $repo;`, 
      `$this->createMock(\Namespace\De\Lapplication\Service\MonService::class)`, 
      `new \Namespace\De\Lapplication\Entity\MonEntite()`,
      `\Namespace\De\Lapplication\Enum\MonEnum::VALEUR`
3. **Vérification globale** : Parcours l'intégralité du fichier de test et convertis *chaque* référence de classe de l'application en son FQCN absolu.