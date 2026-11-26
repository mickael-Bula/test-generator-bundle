<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Generation\GeneratedTestPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GeneratedTestPath::class)]
final class GeneratedTestPathTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function pathProvider(): iterable
    {
        yield 'namespace sous App\Tests' => ['App\Tests\Dynamic\Functional', null, '/srv/app/tests/Dynamic/Functional/FooDynamicTest.php'];
        yield 'namespace App\Tests exact' => ['App\Tests', null, '/srv/app/tests/FooDynamicTest.php'];
        yield 'antislash initial toléré' => ['\App\Tests\E2E', null, '/srv/app/tests/E2E/FooDynamicTest.php'];
        yield 'dossier relatif' => ['App\Tests\X', 'var/out', '/srv/app/var/out/FooDynamicTest.php'];
        yield 'dossier absolu avec barre finale' => ['App\Tests\X', '/tmp/out/', '/tmp/out/FooDynamicTest.php'];
        yield 'fichier relatif' => ['App\Tests\X', 'tests/Mon.php', '/srv/app/tests/Mon.php'];
        yield 'fichier absolu, extension en majuscules' => ['App\Tests\X', '/tmp/Mon.PHP', '/tmp/Mon.PHP'];
        yield 'chemin Windows absolu' => ['App\Tests\X', 'C:\out', 'C:\out/FooDynamicTest.php'];
        yield 'option vide ignorée' => ['App\Tests\Y', '  ', '/srv/app/tests/Y/FooDynamicTest.php'];
    }

    #[Test]
    #[DataProvider('pathProvider')]
    public function testResolvesTheOutputPath(string $namespace, ?string $output, string $expected): void
    {
        // ÉTANT DONNÉ un projet, un namespace et une option --output éventuelle
        // QUAND on résout le chemin du test
        // ALORS il est celui attendu
        self::assertSame($expected, (new GeneratedTestPath())->resolve('/srv/app/', $namespace, 'FooDynamicTest', $output));
    }

    #[Test]
    public function testRefusesAForeignNamespaceWithoutOutput(): void
    {
        // ÉTANT DONNÉ un namespace hors de App\Tests et aucune option --output
        // QUAND on résout le chemin
        // ALORS l'erreur demande --output
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Utilisez --output');

        (new GeneratedTestPath())->resolve('/srv/app', 'Acme\Tests', 'FooDynamicTest');
    }

    #[Test]
    public function testDoesNotConfuseANamespaceThatMerelyStartsWithAppTests(): void
    {
        // ÉTANT DONNÉ un namespace « App\TestsExtra » (préfixe commun mais autre namespace)
        // QUAND on résout le chemin
        // ALORS il est refusé
        $this->expectException(\InvalidArgumentException::class);

        (new GeneratedTestPath())->resolve('/srv/app', 'App\TestsExtra', 'FooDynamicTest');
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function namespaceProvider(): iterable
    {
        yield 'racine tests' => ['/srv/app/tests', 'App\Tests'];
        yield 'sous-dossier' => ['/srv/app/tests/Unit', 'App\Tests\Unit'];
        yield 'deux niveaux, barre finale' => ['/srv/app/tests/Functional/Browser/', 'App\Tests\Functional\Browser'];
        yield 'hors de tests' => ['/srv/app/var/failed_tests', null];
        yield 'préfixe commun seulement' => ['/srv/app/tests-extra/Unit', null];
        yield 'segment qui n\'est pas un identifiant' => ['/srv/app/tests/my-folder', null];
    }

    #[Test]
    #[DataProvider('namespaceProvider')]
    public function testDeducesTheNamespaceFromAFolderUnderTests(string $directory, ?string $expected): void
    {
        // ÉTANT DONNÉ un dossier
        // QUAND on en déduit le namespace
        // ALORS il suit la convention PSR-4 App\Tests, ou est nul hors de tests/
        self::assertSame($expected, (new GeneratedTestPath())->namespaceFor('/srv/app/', $directory));
    }

    #[Test]
    public function testResolvesAFolderRelativeToTheProject(): void
    {
        // ÉTANT DONNÉ des dossiers relatifs et absolus
        $paths = new GeneratedTestPath();

        // QUAND on les résout
        // ALORS les relatifs sont rattachés au projet, sans barre finale
        self::assertSame('/srv/app/tests/Unit', $paths->directory('/srv/app/', 'tests/Unit/'));
        self::assertSame('/tmp/ok', $paths->directory('/srv/app', ' /tmp/ok '));
    }
}
