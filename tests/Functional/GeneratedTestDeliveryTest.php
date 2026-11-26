<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Functional;

use Mika\TestGeneratorBundle\Functional\Generation\GeneratedTestDelivery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GeneratedTestDelivery::class)]
final class GeneratedTestDeliveryTest extends TestCase
{
    private const WITH_USE = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests\\Dynamic\\Functional;\n\nuse App\\Tests\\E2E\\E2ETest;\n\nfinal class MaTest extends E2ETest\n{\n}\n";
    private const SAME_NAMESPACE = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests\\E2E;\n\nfinal class MaTest extends E2ETest\n{\n}\n";

    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/mika_delivery_' . bin2hex(random_bytes(4));
        mkdir($this->project . '/tests/Dynamic/Functional', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    #[Test]
    public function testRelocateChangesTheNamespaceAndKeepsTheBaseClassImport(): void
    {
        // ÉTANT DONNÉ un test généré dans la zone d'attente
        // QUAND on le relocalise dans App\Tests\Unit
        $source = (new GeneratedTestDelivery())->relocate(self::WITH_USE, 'App\Tests\Unit', 'App\Tests\E2E\E2ETest');

        // ALORS seul le namespace change
        self::assertSame(str_replace('Dynamic\Functional', 'Unit', self::WITH_USE), $source);
    }

    #[Test]
    public function testRelocateDropsTheImportWhenTheBaseClassIsInTheNewNamespace(): void
    {
        // ÉTANT DONNÉ un test qui importe sa classe de base
        // QUAND on le relocalise dans le namespace de cette classe
        $source = (new GeneratedTestDelivery())->relocate(self::WITH_USE, 'App\Tests\E2E', 'App\Tests\E2E\E2ETest');

        // ALORS l'import devient inutile et disparaît, sans ligne vide en trop
        self::assertSame(self::SAME_NAMESPACE, $source);
    }

    #[Test]
    public function testRelocateAddsTheImportWhenTheBaseClassIsNoLongerInTheSameNamespace(): void
    {
        // ÉTANT DONNÉ un test sans import (classe de base dans son namespace)
        // QUAND on le relocalise ailleurs
        $source = (new GeneratedTestDelivery())->relocate(self::SAME_NAMESPACE, 'App\Tests\Unit', 'App\Tests\E2E\E2ETest');

        // ALORS l'import est ajouté
        self::assertSame(
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests\\Unit;\n\nuse App\\Tests\\E2E\\E2ETest;\n\nfinal class MaTest extends E2ETest\n{\n}\n",
            $source,
        );
    }

    #[Test]
    public function testPromoteMovesTheFileAndAlignsTheNamespaceWithTheFolder(): void
    {
        // ÉTANT DONNÉ un test dans la zone d'attente
        $staging = $this->project . '/tests/Dynamic/Functional/MaTest.php';
        file_put_contents($staging, self::WITH_USE);

        // QUAND on le promeut dans tests/Unit/Browser
        $target = (new GeneratedTestDelivery())->promote($this->project, $staging, $this->project . '/tests/Unit/Browser', 'App\Tests\E2E\E2ETest', false);

        // ALORS il est dans le dossier cible avec le bon namespace, et la zone d'attente est vidée
        self::assertSame($this->project . '/tests/Unit/Browser/MaTest.php', $target);
        self::assertStringContainsString('namespace App\Tests\Unit\Browser;', (string) file_get_contents($target));
        self::assertFileDoesNotExist($staging);
    }

    #[Test]
    public function testPromoteKeepsTheNamespaceOutsideTheTestsFolder(): void
    {
        // ÉTANT DONNÉ un dossier cible hors de tests/
        $staging = $this->project . '/tests/Dynamic/Functional/MaTest.php';
        file_put_contents($staging, self::WITH_USE);

        // QUAND on promeut le test
        $target = (new GeneratedTestDelivery())->promote($this->project, $staging, $this->project . '/var/ok', 'App\Tests\E2E\E2ETest', false);

        // ALORS le source est copié tel quel
        self::assertSame(self::WITH_USE, file_get_contents($target));
    }

    #[Test]
    public function testPromoteRefusesToOverwriteWithoutForceAndKeepsBothFiles(): void
    {
        // ÉTANT DONNÉ un fichier déjà présent à destination
        $staging = $this->project . '/tests/Dynamic/Functional/MaTest.php';
        file_put_contents($staging, self::WITH_USE);
        mkdir($this->project . '/tests/Unit', 0777, true);
        file_put_contents($this->project . '/tests/Unit/MaTest.php', 'ancien');
        $delivery = new GeneratedTestDelivery();

        // QUAND on promeut sans --force
        try {
            $delivery->promote($this->project, $staging, $this->project . '/tests/Unit', 'App\Tests\E2E\E2ETest', false);
            self::fail('Une exception était attendue.');
        } catch (\RuntimeException $e) {
            // ALORS rien n'est perdu
            self::assertStringContainsString('Le fichier existe déjà', $e->getMessage());
            self::assertSame('ancien', file_get_contents($this->project . '/tests/Unit/MaTest.php'));
            self::assertFileExists($staging);
        }

        // QUAND on promeut avec --force
        $delivery->promote($this->project, $staging, $this->project . '/tests/Unit', 'App\Tests\E2E\E2ETest', true);

        // ALORS l'ancien est remplacé
        self::assertStringContainsString('final class MaTest', (string) file_get_contents($this->project . '/tests/Unit/MaTest.php'));
    }

    #[Test]
    public function testPromoteIntoTheWaitingFolderItselfDoesNotDeleteTheTest(): void
    {
        // ÉTANT DONNÉ un dossier vert identique à la zone d'attente
        $staging = $this->project . '/tests/Dynamic/Functional/MaTest.php';
        file_put_contents($staging, self::WITH_USE);

        // QUAND on promeut le test
        $target = (new GeneratedTestDelivery())->promote($this->project, $staging, $this->project . '/tests/Dynamic/Functional', 'App\Tests\E2E\E2ETest', false);

        // ALORS le fichier est toujours là
        self::assertSame($staging, $target);
        self::assertFileExists($staging);
    }

    #[Test]
    public function testQuarantineMovesTheTestAndWritesTheLogBesideIt(): void
    {
        // ÉTANT DONNÉ un test rouge dans la zone d'attente
        $staging = $this->project . '/tests/Dynamic/Functional/MaTest.php';
        file_put_contents($staging, self::WITH_USE);

        // QUAND on le met de côté
        [$test, $log] = (new GeneratedTestDelivery())->quarantine($staging, $this->project . '/var/failed_tests/functional', "FAILURES!\n");

        // ALORS le source est intact, le journal est à côté, la zone d'attente est vidée
        self::assertSame($this->project . '/var/failed_tests/functional/MaTest.php', $test);
        self::assertSame($this->project . '/var/failed_tests/functional/MaTest.log', $log);
        self::assertSame(self::WITH_USE, file_get_contents($test));
        self::assertSame("FAILURES!\n", file_get_contents($log));
        self::assertFileDoesNotExist($staging);
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }

        foreach (glob($path . '/{,.}*', GLOB_BRACE) ?: [] as $child) {
            if (!in_array(basename($child), ['.', '..'], true)) {
                $this->remove($child);
            }
        }

        if (is_dir($path)) {
            rmdir($path);
        }
    }
}
