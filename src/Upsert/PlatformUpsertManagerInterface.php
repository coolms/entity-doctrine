<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Upsert;

/**
 * Connection-aware facade over {@see PlatformUpsertInterface}.
 *
 * Repositories autowire this; the manager resolves the right
 * per-platform impl from {@see PlatformUpsertFactoryInterface} on
 * each call and forwards. The factory's `match (true)` over
 * `Connection::getDatabasePlatform()` is the dispatch point, mirroring
 * Ship A's {@see \CoolMS\Entity\Doctrine\Schema\Platform\PlatformSchemaManagerFactory}.
 */
interface PlatformUpsertManagerInterface
{
    /**
     * @see PlatformUpsertInterface::upsert()
     *
     * @param array<string,mixed> $data
     * @param list<string>        $conflictColumns
     * @param ?list<string>       $updateColumns
     */
    public function upsert(
        string $table,
        array $data,
        array $conflictColumns,
        ?array $updateColumns = null,
    ): int;

    /**
     * @see PlatformUpsertInterface::upsertCoalesce()
     *
     * @param array<string,mixed> $data
     * @param list<string>        $conflictColumns
     * @param list<string>        $coalesceColumns
     */
    public function upsertCoalesce(
        string $table,
        array $data,
        array $conflictColumns,
        array $coalesceColumns,
    ): int;

    /**
     * @see PlatformUpsertInterface::insertIfMissing()
     *
     * @param array<string,mixed> $data
     * @param list<string>        $conflictColumns
     */
    public function insertIfMissing(
        string $table,
        array $data,
        array $conflictColumns,
    ): int;
}
