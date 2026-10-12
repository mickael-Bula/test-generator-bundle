# SKILL : INTERDICTION DE RÉ-INSTANCIER LA SUT DANS LES MÉTHODES DE TEST (ArgumentCountError)

Le rapport d'erreur indique une `ArgumentCountError` car la classe testée (SUT) a été ré-instanciée manuellement 
à l'intérieur d'une méthode de test avec un nombre incorrect d'arguments.

CONSIGNES DE CORRECTION :
1. **Instance unique via le `setUp()`** :
    - La SUT **doit** être instanciée une seule et unique fois dans la méthode native `setUp()` et stockée dans une propriété de classe (ex : `$this->sut`).
    - Il est **formellement interdit** d'écrire `new MaClasse(...)` à l'intérieur des méthodes de test (`#[Test]`).
2. **Utilisation exclusive de la propriété partagée** :
    - Dans tes méthodes de test, appelle systématiquement la méthode sous test via l'instance initialisée dans le setUp (ex : `$this->sut->maMethode(...)`).
    - Supprime toutes les instanciations manuelles redondantes qui omettent des arguments du constructeur.