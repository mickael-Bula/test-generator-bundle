<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Tests\TestGenerator\Fixture;

final readonly class AuditLogger
{
    public function __construct(private RecordStoreInterface $store)
    {
    }

    /**
     * @param array<string, string|null> $old
     * @param array<string, string|null> $new
     */
    public function logUpdate(int $subjectId, array $old, array $new): void
    {
        foreach ($new as $field => $value) {
            $before = $old[$field] ?? null;
            if ($before === $value) {
                continue;
            }

            $this->store->persist([
                'subject' => $subjectId,
                'action' => 'UPDATE',
                'field' => $field,
                'old' => $before,
                'new' => $value,
            ]);
        }
    }

    public function logDelete(int $subjectId): void
    {
        $this->store->persist(['subject' => $subjectId, 'action' => 'DELETE']);
    }
}
