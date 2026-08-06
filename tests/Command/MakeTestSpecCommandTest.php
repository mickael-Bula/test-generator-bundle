<?php

namespace Mika\TestGeneratorBundle\Tests\Command;

use Random\RandomException;
use PHPUnit\Framework\TestCase;
use Mika\TestGeneratorBundle\Command\MakeTestSpecCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Exception\ExceptionInterface;

#[CoversClass(MakeTestSpecCommand::class)]
class MakeTestSpecCommandTest extends TestCase
{
    private Filesystem $filesystem;
    private string $tempDir;
    private MakeTestSpecCommand $command;

    /**
     * @throws RandomException
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir() . '/test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0777, true);

        // Créer un dossier de projet temporaire pour simuler le %kernel.project_dir%
        $projectDir = $this->tempDir . '/project';
        mkdir($projectDir, 0777, true);
        mkdir($projectDir . '/tests/Specs', 0777, true);

        // Créer un dossier de bundle temporaire
        $bundleDir = $this->tempDir . '/bundle';
        mkdir($bundleDir, 0777, true);
        mkdir($bundleDir . '/Resources/spec-templates', 0777, true);

        // Créer des templates fictifs de spécification
        file_put_contents($bundleDir . '/Resources/spec-templates/test_spec_template.md', 'Template for method spec');
        file_put_contents($bundleDir . '/Resources/spec-templates/test_spec_class_template.md', 'Template for class spec');

        $this->command = new MakeTestSpecCommand($projectDir);
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->exists($this->tempDir)) {
            $this->filesystem->chmod($this->tempDir, 0777, 0000, true);
            $this->filesystem->remove($this->tempDir);
        }
    }

    /**
     * @throws ExceptionInterface
     */
    #[Test]
    public function testGenerateClassSpecFile(): void
    {
        // ÉTANT DONNÉ
        $className = 'App\Service\VatCalculator';

        // QUAND
        $input = new ArrayInput(['class' => $className]);
        $output = new BufferedOutput();
        $this->command->run($input, $output);

        // ALORS
        $expectedTargetPath = $this->tempDir . '/project/tests/Specs/VatCalculatorSpec.md';
        self::assertFileExists($expectedTargetPath);
    }

    /**
     * @throws ExceptionInterface
     */
    #[Test]
    public function testGenerateMethodSpecFile(): void
    {
        // ÉTANT DONNÉ
        $className = 'App\Service\VatCalculator';
        $methodName = 'calculateVatAmount';

        // QUAND
        $input = new ArrayInput(['class' => $className, '--method' => $methodName]);
        $output = new BufferedOutput();
        $this->command->run($input, $output);

        // ALORS
        $expectedTargetPath = $this->tempDir . '/project/tests/Specs/VatCalculator_calculateVatAmountSpec.md';
        self::assertFileExists($expectedTargetPath);
    }
}