<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Upsert;

use Doctrine\DBAL\Connection;

/**
 * Connection-aware facade over the per-platform upsert impls.
 *
 * Repositories autowire this; the manager memoises the resolved
 * impl on first use so per-call factory dispatch costs nothing
 * after warm-up. The connection's platform does not change for the
 * lifetime of the kernel, so the cache is safe.
 */
final class PlatformUpsertManager implements PlatformUpsertManagerInterface
{
    private ?PlatformUpsertInterface $upsert = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly PlatformUpsertFactoryInterface $factory,
    ) {
    }

    public function upsert(string $table, array $data, array $conflictColumns, ?array $updateColumns = null): int
    {
        return $this->resolve()->upsert($table, $data, $conflictColumns, $updateColumns);
    }

    public function upsertCoalesce(string $table, array $data, array $conflictColumns, array $coalesceColumns): int
    {
        return $this->resolve()->upsertCoalesce($table, $data, $conflictColumns, $coalesceColumns);
    }

    public function insertIfMissing(string $table, array $data, array $conflictColumns): int
    {
        return $this->resolve()->insertIfMissing($table, $data, $conflictColumns);
    }

    private function resolve(): PlatformUpsertInterface
    {
        return $this->upsert ??= $this->factory->create($this->connection);
    }
}
