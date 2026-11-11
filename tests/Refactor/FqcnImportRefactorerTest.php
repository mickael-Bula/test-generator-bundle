<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Refactor;

use Mika\TestGeneratorBundle\Refactor\FqcnImportRefactorer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FqcnImportRefactorer::class)]
final class FqcnImportRefactorerTest extends TestCase
{
    #[Test]
    public function testRefactorAddsUseAndShortensInlineFqcn(): void
    {
        // ÉTANT DONNÉ
        $code = implode("\n", [
            '<?php',
            '',
            'namespace App\Tests\Dynamic;',
            '',
            'final class SomeTest',
            '{',
            '    public function run(): void',
            '    {',
            '        $user = new \App\Entity\User();',
            '    }',
            '}',
        ]);
        $refactorer = new FqcnImportRefactorer();

        // QUAND
        $result = $refactorer->refactor($code);

        // ALORS
        self::assertStringContainsString('use App\Entity\User;', $result);
        self::assertStringContainsString('new User()', $result);
        self::assertStringNotContainsString('\App\Entity\User', $result);
    }

    #[Test]
    public function testRefactorDoesNotDuplicateAnExistingImport(): void
    {
        // ÉTANT DONNÉ
        $code = implode("\n", [
            '<?php',
            '',
            'namespace App\Tests\Dynamic;',
            '',
            'use App\Entity\Entrypoint;',
            '',
            'final class SomeTest',
            '{',
            '    public function run(): void',
            '    {',
            '        $a = new Entrypoint();',
            '        $b = new \App\Entity\Entrypoint();',
            '        $c = new \App\Entity\Position();',
            '    }',
            '}',
        ]);
        $refactorer = new FqcnImportRefactorer();

        // QUAND
        $result = $refactorer->refactor($code);

        // ALORS
        self::assertSame(1, substr_count($result, 'use App\Entity\Entrypoint;'));
        self::assertStringContainsString('use App\Entity\Position;', $result);
        self::assertStringNotContainsString('\App\Entity\Entrypoint', $result);
    }

    #[Test]
    public function testRefactorKeepsInlineFqcnWhenAnotherClassHoldsTheShortName(): void
    {
        // ÉTANT DONNÉ
        $code = implode("\n", [
            '<?php',
            '',
            'namespace App\Tests\Dynamic;',
            '',
            'use Vendor\Lib\Entrypoint;',
            '',
            'final class SomeTest',
            '{',
            '    public function run(): void',
            '    {',
            '        $a = new \App\Entity\Entrypoint();',
            '    }',
            '}',
        ]);
        $refactorer = new FqcnImportRefactorer();

        // QUAND
        $result = $refactorer->refactor($code);

        // ALORS
        self::assertSame($code, $result);
    }

    #[Test]
    public function testRefactorKeepsSecondFqcnInlineWhenTwoShareTheSameShortName(): void
    {
        // ÉTANT DONNÉ
        $code = implode("\n", [
            '<?php',
            '',
            'namespace App\Tests\Dynamic;',
            '',
            'final class SomeTest',
            '{',
            '    public function run(): void',
            '    {',
            '        $a = new \App\Entity\Position();',
            '        $b = new \App\Dto\Position();',
            '    }',
            '}',
        ]);
        $refactorer = new FqcnImportRefactorer();

        // QUAND
        $result = $refactorer->refactor($code);

        // ALORS
        self::assertStringContainsString('use App\Entity\Position;', $result);
        self::assertStringNotContainsString('use App\Dto\Position;', $result);
        self::assertStringContainsString('$a = new Position();', $result);
        self::assertStringContainsString('$b = new \App\Dto\Position();', $result);
    }
}
