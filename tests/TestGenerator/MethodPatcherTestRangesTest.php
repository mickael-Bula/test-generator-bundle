<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator;

use Mika\TestGeneratorBundle\Quality\MethodPatcher;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class MethodPatcherTestRangesTest extends TestCase
{
    public function testTestRangesRendLesPlagesDesSeulsTestsAttributsCompris(): void
    {
        // ÉTANT DONNÉ une classe avec setUp(), un test par attribut, un test par nom et un helper
        $code = <<<'PHP'
        <?php

        final class SampleTest
        {
            private int $x = 1;

            protected function setUp(): void
            {
            }

            #[\PHPUnit\Framework\Attributes\Test]
            public function checksSomething(): void
            {
                self::assertTrue(true);
            }

            public function testPlain(): void
            {
                self::assertTrue(true);
            }

            private function helper(): void
            {
            }
        }
        PHP;
        $patcher = new MethodPatcher((new ParserFactory())->createForNewestSupportedVersion());

        // QUAND on demande les plages de lignes des tests
        $ranges = $patcher->testRanges($code);

        // ALORS seuls les deux tests sont rendus, l'attribut compris dans la plage
        self::assertSame([
            'checksSomething' => ['start' => 11, 'end' => 15],
            'testPlain' => ['start' => 17, 'end' => 20],
        ], $ranges);
    }

    public function testTestRangesRendUnTableauVideSurUnCodeInvalide(): void
    {
        // ÉTANT DONNÉ un code qui ne se parse pas
        $patcher = new MethodPatcher((new ParserFactory())->createForNewestSupportedVersion());

        // QUAND / ALORS aucune plage
        self::assertSame([], $patcher->testRanges('<?php class {'));
    }
}
