<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Upsert\Platform;

use CoolMS\Entity\Doctrine\Upsert\PlatformUpsertInterface;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;

/**
 * Shared scaffolding for the per-platform upsert implementations.
 *
 * Centralises argument validation (non-empty column lists, conflict
 * keys present in $data) plus identifier quoting via the live
 * connection's platform quoter. Platform-specific SQL assembly stays
 * in subclasses; this base only owns the cross-cutting plumbing.
 */
abstract class AbstractPlatformUpsert implements PlatformUpsertInterface
{
    public function __construct(
        protected readonly Connection $connection,
    ) {
    }

    public function upsert(
        string $table,
        array $data,
        array $conflictColumns,
        ?array $updateColumns = null,
    ): int {
        $this->assertWritable($table, $data, $conflictColumns);
        $updateColumns ??= array_values(array_diff(array_keys($data), $conflictColumns));
        $this->assertColumnsKnown($data, $updateColumns, 'updateColumns');

        $sql = $this->buildUpsertSql($table, array_keys($data), $conflictColumns, $updateColumns);

        return (int) $this->connection->executeStatement($sql, $data);
    }

    public function upsertCoalesce(
        string $table,
        array $data,
        array $conflictColumns,
        array $coalesceColumns,
    ): int {
        $this->assertWritable($table, $data, $conflictColumns);
        if ([] === $coalesceColumns) {
            throw new InvalidArgumentException('upsertCoalesce requires at least one coalesce column.');
        }
        $this->assertColumnsKnown($data, $coalesceColumns, 'coalesceColumns');

        $sql = $this->buildUpsertCoalesceSql($table, array_keys($data), $conflictColumns, $coalesceColumns);

        return (int) $this->connection->executeStatement($sql, $data);
    }

    public function insertIfMissing(
        string $table,
        array $data,
        array $conflictColumns,
    ): int {
        $this->assertWritable($table, $data, $conflictColumns);
        $sql = $this->buildInsertIfMissingSql($table, array_keys($data), $conflictColumns);

        return (int) $this->connection->executeStatement($sql, $data);
    }

    /**
     * @param list<string> $columns
     * @param list<string> $conflictColumns
     * @param list<string> $updateColumns
     */
    abstract protected function buildUpsertSql(
        string $table,
        array $columns,
        array $conflictColumns,
        array $updateColumns,
    ): string;

    /**
     * @param list<string> $columns
     * @param list<string> $conflictColumns
     * @param list<string> $coalesceColumns
     */
    abstract protected function buildUpsertCoalesceSql(
        string $table,
        array $columns,
        array $conflictColumns,
        array $coalesceColumns,
    ): string;

    /**
     * @param list<string> $columns
     * @param list<string> $conflictColumns
     */
    abstract protected function buildInsertIfMissingSql(
        string $table,
        array $columns,
        array $conflictColumns,
    ): string;

    /**
     * @param array<string,mixed> $data
     * @param list<string>        $conflictColumns
     */
    protected function assertWritable(string $table, array $data, array $conflictColumns): void
    {
        if ('' === $table) {
            throw new InvalidArgumentException('Upsert table name cannot be empty.');
        }
        if ([] === $data) {
            throw new InvalidArgumentException('Upsert requires at least one column => value pair in $data.');
        }
        if ([] === $conflictColumns) {
            throw new InvalidArgumentException('Upsert requires at least one conflict column.');
        }
        $this->assertColumnsKnown($data, $conflictColumns, 'conflictColumns');
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string>        $columns
     */
    protected function assertColumnsKnown(array $data, array $columns, string $label): void
    {
        foreach ($columns as $col) {
            if (!array_key_exists($col, $data)) {
                throw new InvalidArgumentException(sprintf('Upsert %s contains "%s" which is not a key in $data.', $label, $col));
            }
        }
    }

    protected function quote(string $identifier): string
    {
        return $this->connection->getDatabasePlatform()->quoteSingleIdentifier($identifier);
    }

    /**
     * Renders `(col1, col2, ...)` with each identifier quoted.
     *
     * @param list<string> $columns
     */
    protected function quotedColumnList(array $columns): string
    {
        return '(' . implode(', ', array_map($this->quote(...), $columns)) . ')';
    }

    /**
     * Renders `(:col1, :col2, ...)` for the VALUES clause.
     *
     * @param list<string> $columns
     */
    protected function placeholderList(array $columns): string
    {
        return '(' . implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)) . ')';
    }
}
