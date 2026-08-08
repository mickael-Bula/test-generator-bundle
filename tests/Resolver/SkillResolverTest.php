<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\Resolver;

use Mika\TestGeneratorBundle\Command\GenerateTestCommand;
use Mika\TestGeneratorBundle\Resolver\SkillResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(SkillResolver::class)]
final class SkillResolverTest extends TestCase
{
    private string $tempDir;
    private string $nativeSkillsDir;
    private string $customSkillsDir;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir() . '/test_skill_resolver_' . bin2hex(random_bytes(8));
        $this->nativeSkillsDir = $this->tempDir . '/native';
        $this->customSkillsDir = $this->tempDir . '/custom';

        $this->filesystem->mkdir($this->nativeSkillsDir);
        $this->filesystem->mkdir($this->customSkillsDir);
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->exists($this->tempDir)) {
            $this->filesystem->chmod($this->tempDir, 0777, 0000, true);
            $this->filesystem->remove($this->tempDir);
        }
    }

    #[Test]
    public function testResolveForClassWithSymfonyCommandSubclass(): void
    {
        // ÉTANT DONNÉ
        $this->filesystem->dumpFile($this->nativeSkillsDir . '/symfony_command.md', 'Symfony Command Skill');
        $resolver = new SkillResolver(null, $this->nativeSkillsDir);

        // QUAND
        $result = $resolver->resolveForClass(GenerateTestCommand::class, 'class SomeClass {}');

        // ALORS
        $this->assertStringContainsString('Symfony Command Skill', $result);
    }

    #[Test]
    public function testResolveForClassWithExtendsCommandCode(): void
    {
        // ÉTANT DONNÉ
        $this->filesystem->dumpFile($this->nativeSkillsDir . '/symfony_command.md', 'Symfony Command Skill');
        $resolver = new SkillResolver(null, $this->nativeSkillsDir);
        $code = 'class MyCommand extends Command {}';

        // QUAND
        $result = $resolver->resolveForClass('App\Command\MyCommand', $code);

        // ALORS
        $this->assertStringContainsString('Symfony Command Skill', $result);
    }

    #[Test]
    public function testResolveForClassWithAsCommandAttribute(): void
    {
        // ÉTANT DONNÉ
        $this->filesystem->dumpFile($this->nativeSkillsDir . '/symfony_command.md', 'Symfony Command Skill');
        $resolver = new SkillResolver(null, $this->nativeSkillsDir);
        $code = '#[AsCommand(name: "app:test")] class MyCommand {}';

        // QUAND
        $result = $resolver->resolveForClass('App\Command\MyCommand', $code);

        // ALORS
        $this->assertStringContainsString('Symfony Command Skill', $result);
    }

    #[Test]
    public function testResolveForClassWithoutSymfonyCommand(): void
    {
        // ÉTANT DONNÉ
        $this->filesystem->dumpFile($this->nativeSkillsDir . '/symfony_command.md', 'Symfony Command Skill');
        $resolver = new SkillResolver(null, $this->nativeSkillsDir);

        // QUAND
        $result = $resolver->resolveForClass('App\Service\SimpleService', 'class SimpleService {}');

        // ALORS
        $this->assertStringNotContainsString('Symfony Command Skill', $result);
    }

    #[Test]
    public function testResolveForClassWithNativeFilesystemFunctions(): void
    {
        // ÉTANT DONNÉ
        $this->filesystem->dumpFile($this->nativeSkillsDir . '/filesystem_test.md', 'Filesystem Skill');
        $resolver = new SkillResolver(null, $this->nativeSkillsDir);
        $code = 'function test() { file_put_contents("file.txt", "content"); }';

        // QUAND
        $result = $resolver->resolveForClass('App\Service\FileWriter', $code);

        // ALORS
        $this->assertStringContainsString('Filesystem Skill', $result);
    }

    #[Test]
    public function testResolveForClassWithSymfonyFinderImport(): void
    {
        // ÉTANT DONNÉ
        $this->filesystem->dumpFile($this->nativeSkillsDir . '/filesystem_test.md', 'Filesystem Skill');
        $resolver = new SkillResolver(null, $this->nativeSkillsDir);
        $code = 'use Symfony\Component\Finder\Finder;';

        // QUAND
        $result = $resolver->resolveForClass('App\Service\SearchService', $code);

        // ALORS
        $this->assertStringContainsString('Filesystem Skill', $result);
    }

    #[Test]
    public function testResolveForClassWithSymfonyFilesystemImport(): void
    {
        // ÉTANT DONNÉ
        $this->filesystem->dumpFile($this->nativeSkillsDir . '/filesystem_test.md', 'Filesystem Skill');
        $resolver = new SkillResolver(null, $this->nativeSkillsDir);
        $code = 'use Symfony\Component\Filesystem\Filesystem;';

        // QUAND
        $result = $resolver->resolveForClass('App\Service\FileStorage', $code);

        // ALORS
        $this->assertStringContainsString('Filesystem Skill', $result);
    }

    #[Test]
    public function testResolveForClassWithFilesystemFalsePositives(): void
    {
        // ÉTANT DONNÉ
        $this->filesystem->dumpFile($this->nativeSkillsDir . '/filesystem_test.md', 'Filesystem Skill');
        $resolver = new SkillResolver(null, $this->nativeSkillsDir);
        $code = 'class UserFinder { private $myFilesystem; }';

        // QUAND
        $result = $resolver->resolveForClass('App\Service\UserFinder', $code);

        // ALORS
        $this->assertStringNotContainsString('Filesystem Skill', $result);
    }

    #[Test]
    public function testResolveForClassWithPhpParserNamespace(): void
    {
        // ÉTANT DONNÉ
        $this->filesystem->dumpFile($this->nativeSkillsDir . '/php-parser-v5.md', 'PhpParser Skill');
        $resolver = new SkillResolver(null, $this->nativeSkillsDir);
        $code = 'use PhpParser\Node;';

        // QUAND
        $result = $resolver->resolveForClass('App\Visitor\MyVisitor', $code);

        // ALORS
        $this->assertStringContainsString('PhpParser Skill', $result);
    }

    #[Test]
    public function testResolveForClassWithPhpParserUseStatement(): void
    {
        // ÉTANT DONNÉ
        $this->filesystem->dumpFile($this->nativeSkillsDir . '/php-parser-v5.md', 'PhpParser Skill');
        $resolver = new SkillResolver(null, $this->nativeSkillsDir);
        $code = 'use PhpParser;';

        // QUAND
        $result = $resolver->resolveForClass('App\Visitor\MyVisitor', $code);

        // ALORS
        $this->assertStringContainsString('PhpParser Skill', $result);
    }

    #[Test]
    public function testResolveForClassDeduplicatesSkills(): void
    {
        // ÉTANT DONNÉ
        $this->filesystem->dumpFile($this->nativeSkillsDir . '/filesystem_test.md', 'Filesystem Skill');
        $resolver = new SkillResolver(null, $this->nativeSkillsDir);
        $code = 'use Symfony\Component\Finder\Finder; file_put_contents("a", "b"); mkdir("dir");';

        // QUAND
        $result = $resolver->resolveForClass('App\Service\ComplexFileHandler', $code);

        // ALORS
        $this->assertSame('Filesystem Skill', $result);
    }

    #[Test]
    public function testResolveForClassIgnoresMissingNativeSkillFile(): void
    {
        // ÉTANT DONNÉ
        $resolver = new SkillResolver(null, $this->nativeSkillsDir);
        $code = 'use Symfony\Component\Finder\Finder;';

        // QUAND
        $result = $resolver->resolveForClass('App\Service\SearchService', $code);

        // ALORS
        $this->assertSame('', $result);
    }

    #[Test]
    public function testResolveForClassWithNullOrInvalidCustomSkillsDirectory(): void
    {
        // ÉTANT DONNÉ
        $resolver = new SkillResolver($this->tempDir . '/non_existent', $this->nativeSkillsDir);

        // QUAND
        $result = $resolver->resolveForClass('App\Service\Dummy', 'class Dummy {}');

        // ALORS
        $this->assertSame('', $result);
    }

    #[Test]
    public function testResolveForClassAppendsCustomSkills(): void
    {
        // ÉTANT DONNÉ
        $this->filesystem->dumpFile($this->nativeSkillsDir . '/symfony_command.md', 'Native Command Skill');
        $this->filesystem->dumpFile($this->customSkillsDir . '/skill1.md', 'Custom Skill 1');
        $this->filesystem->dumpFile($this->customSkillsDir . '/skill2.md', 'Custom Skill 2');
        $resolver = new SkillResolver($this->customSkillsDir, $this->nativeSkillsDir);
        $code = 'class MyCommand extends Command {}';

        // QUAND
        $result = $resolver->resolveForClass('App\Command\MyCommand', $code);

        // ALORS
        $this->assertStringContainsString('Native Command Skill', $result);
    }
}