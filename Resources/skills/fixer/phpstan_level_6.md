### Directives PHPStan (Niveau 6) pour PHPUnit :

1. **Typage des Mocks (`createMock`) :**
   Pour éviter l'erreur `has unresolvable native type` ou `contains unresolvable type` :
   - **OBLIGATION** : Déclare la propriété du mock avec une **UNION** de types natifs PHP : `private MockObject|NomDeLaClasse $propriete;`.
   - **INTERDICTION** : Ne JAMAIS utiliser d'intersection `&` (ex: `MockObject&NomDeLaClasse`), ni de PHPDoc `@var` pour la propriété.

   Exemple valide :
   ```php
   use PHPUnit\Framework\TestCase;
   use PHPUnit\Framework\Attributes\Test;
   use PHPUnit\Framework\MockObject\MockObject;
   use App\Service\PriceCalculator;

   class DiscountProcessorTest extends TestCase
   {
       // Type d'union natif PHP (SANS PHPDoc, SANS intersection &)
       private MockObject|PriceCalculator $calculator;
       private DiscountProcessor $processor;

       protected function setUp(): void
       {
           $this->calculator =$this->createMock(PriceCalculator::class);
           $this->processor = new DiscountProcessor($this->calculator);
       }

       #[Test]
       public function testProcess(): void
       {
           // Valide pour PHPStan grâce à MockObject dans l'union
           $this->calculator->method('calculateVat')->willReturn(20.0);
       }
   }
    ```
2. **Typage des Data Providers (`@return`) :**
   PHPStan Niveau 6 interdit les retours `array` non typés sur les itérables et les Data Providers.
   - Ne JAMAIS utiliser `@return array` ou `public static function provideData(): array` sans DocBlock détaillé.
   - Spécifie toujours la structure précise du tableau dans le PHPDoc.

   Exemple valide :
    
    ```php
    /**
    * @return array<string, array{0: int|float, 1: int|float}>
    */
    public static function providePricesForCalculation(): array
    {
        return [
            'remise classique' => [100, 80],
            'sans remise' => [50, 50],
        ];
    }
    ```
3. **Ordre entre PHPDoc et Attributs PHP (`#[DataProvider]`, `#[Test]`, etc.) :**
   En PHP, le bloc PHPDoc (`/** ... */`) doit impérativement être placé AVANT les attributs PHP (`#[...]`), juste au-dessus de ces derniers.
   - **INTERDICTION :** Ne JAMAIS intercaler un attribut PHP entre le bloc PHPDoc et la fonction. 
     Cela détache le bloc de la fonction et empêche PHPStan de lire les annotations (`@param`, `@return`, etc.).

   Exemple INVALIDE (PHPStan ignore `@param` et lève une erreur de typage) :
   
   ```php
   #[Test]
   #[DataProvider('providePricesForCalculation')]
   /**
   * @param int|float $originalPrice
   * @param int|float $expectedPrice
   */
   public function testCalculate(mixed $originalPrice, mixed$expectedPrice): void
   {
   // ...
   }
   ```
   
   Exemple VALIDE :
 
    ```php
    /**
    * @param int|float $originalPrice
    * @param int|float $expectedPrice
    */
   #[Test]
   #[DataProvider('providePricesForCalculation')]
   public function testCalculate(mixed $originalPrice, mixed$expectedPrice): void
   {
       // ...
   }
   ```
