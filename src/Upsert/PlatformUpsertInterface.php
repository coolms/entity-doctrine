<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Upsert;

/**
 * DQL Ship B -- portable upsert dispatch.
 *
 * Sibling to {@see \CoolMS\Entity\Doctrine\Schema\Platform\PlatformSchemaManagerInterface}.
 * Each implementation knows how to translate the three core upsert
 * semantics into the SQL flavour of one Doctrine DBAL platform:
 *
 *   - PostgreSQL / SQLite -- `INSERT ... ON CONFLICT (cols) DO UPDATE/NOTHING`
 *   - MySQL / MariaDB     -- `INSERT ... ON DUPLICATE KEY UPDATE` (DO NOTHING via `INSERT IGNORE`)
 *   - SQL Server / Oracle -- `MERGE INTO ... USING (VALUES ...) ON ... WHEN MATCHED/NOT MATCHED`
 *
 * Callers use the {@see PlatformUpsertManager} (autowired) to dispatch
 * to the right impl based on the live connection's platform; this
 * interface is the contract the manager bridges to.
 *
 * All column / table names are quoted via the platform's identifier
 * quoter at SQL-build time. Values are passed as parameter bindings
 * (named placeholders), never interpolated into the SQL string.
 *
 * MSSQL + Oracle implementations are wired but UNTESTED IN CI -- the
 * project ships against PostgreSQL only; the MERGE templates are
 * present so a downstream consumer can flip the connection driver
 * without rewriting repositories.
 */
interface PlatformUpsertInterface
{
    /**
     * Insert a row, or update the named columns on conflict.
     *
     * On conflict, each $updateColumns column is set to its newly-
     * proposed value (`EXCLUDED.col` on PG/SQLite, `VALUES(col)` on
     * MySQL, the source-side reference on MERGE platforms).
     *
     * @param string              $table           unqualified table name (will be quoted)
     * @param array<string,mixed> $data            column => bound value (all columns to insert)
     * @param list<string>        $conflictColumns columns forming the conflict constraint
     * @param ?list<string>       $updateColumns   columns to update on conflict; null => every
     *                                             non-conflict key in $data
     *
     * @return int affected row count returned by the driver
     */
    public function upsert(
        string $table,
        array $data,
        array $conflictColumns,
        ?array $updateColumns = null,
    ): int;

    /**
     * Insert a row, or update the named columns by COALESCE-merging
     * the proposed value with the existing one on conflict.
     *
     * For each column in $coalesceColumns the conflict branch sets
     *   col = COALESCE(<proposed>, <existing>)
     * so that callers passing null for a column preserve any prior
     * non-null value -- the semantic Notification's `upsertUserState`
     * relies on.
     *
     * @param string              $table           unqualified table name
     * @param array<string,mixed> $data            column => bound value
     * @param list<string>        $conflictColumns columns forming the conflict constraint
     * @param list<string>        $coalesceColumns columns whose update branch should COALESCE
     *
     * @return int affected row count returned by the driver
     */
    public function upsertCoalesce(
        string $table,
        array $data,
        array $conflictColumns,
        array $coalesceColumns,
    ): int;

    /**
     * Insert a row, or do nothing on conflict.
     *
     * @param string              $table           unqualified table name
     * @param array<string,mixed> $data            column => bound value
     * @param list<string>        $conflictColumns columns forming the conflict constraint
     *
     * @return int 1 if inserted, 0 if skipped (driver-dependent: MERGE
     *             platforms may always return 1; treat as advisory)
     */
    public function insertIfMissing(
        string $table,
        array $data,
        array $conflictColumns,
    ): int;
}
