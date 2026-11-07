<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator;

use Mika\TestGeneratorBundle\Tests\TestGenerator\Fixture\ExportableDto;
use Mika\TestGeneratorBundle\Tests\TestGenerator\Fixture\Status;
use Mika\TestGeneratorBundle\Reflection\ObjectExporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObjectExporter::class)]
final class ObjectExporterTest extends TestCase
{
    #[Test]
    public function privatePropertiesAreExportedWhereJsonEncodeReturnsAnEmptyObject(): void
    {
        // ÉTANT DONNÉ
        $dto = new ExportableDto('Demo', [new ExportableDto('Child', [], Status::Closed)], Status::Open);

        // QUAND
        $exported = ObjectExporter::toArray($dto);

        // ALORS
        self::assertSame('{}', json_encode($dto)); // le défaut observé dans SpecManager
        self::assertSame(
            [
                'name' => 'Demo',
                'items' => [['name' => 'Child', 'items' => [], 'status' => 'closed', 'count' => null]],
                'status' => 'open',
                'count' => null,
            ],
            $exported,
        );
    }

    #[Test]
    public function uninitializedTypedPropertyBecomesNull(): void
    {
        // ÉTANT DONNÉ
        $object = new class {
            private int $unset;
        };

        // ALORS
        self::assertSame(['unset' => null], ObjectExporter::toArray($object));
    }

    #[Test]
    public function objectWithoutPropertiesStaysAJsonObject(): void
    {
        // ALORS
        self::assertSame('{}', json_encode(ObjectExporter::toArray(new \stdClass())));
    }
}
