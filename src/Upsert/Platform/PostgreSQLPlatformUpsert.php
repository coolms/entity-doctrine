<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Upsert\Platform;

/**
 * PostgreSQL: `INSERT ... ON CONFLICT (cols) DO UPDATE SET col = EXCLUDED.col`.
 *
 * The `EXCLUDED` pseudo-table exposes the row that would have been
 * inserted; `EXCLUDED.<col>` resolves to the new value and the
 * table-qualified `<table>.<col>` refers to the existing row.
 *
 * `DO NOTHING` is the dialect's native insert-if-missing.
 */
class PostgreSQLPlatformUpsert extends AbstractPlatformUpsert
{
    protected function buildUpsertSql(string $table, array $columns, array $conflictColumns, array $updateColumns): string
    {
        $qTable = $this->quote($table);
        $cols = $this->quotedColumnList($columns);
        $values = $this->placeholderList($columns);
        $conflict = $this->quotedColumnList($conflictColumns);
        $assignments = implode(', ', array_map(
            fn (string $c): string => sprintf('%s = EXCLUDED.%s', $this->quote($c), $this->quote($c)),
            $updateColumns,
        ));

        return sprintf(
            'INSERT INTO %s %s VALUES %s ON CONFLICT %s DO UPDATE SET %s',
            $qTable,
            $cols,
            $values,
            $conflict,
            $assignments,
        );
    }

    protected function buildUpsertCoalesceSql(string $table, array $columns, array $conflictColumns, array $coalesceColumns): string
    {
        $qTable = $this->quote($table);
        $cols = $this->quotedColumnList($columns);
        $values = $this->placeholderList($columns);
        $conflict = $this->quotedColumnList($conflictColumns);
        $assignments = implode(', ', array_map(
            fn (string $c): string => sprintf(
                '%s = COALESCE(EXCLUDED.%s, %s.%s)',
                $this->quote($c),
                $this->quote($c),
                $qTable,
                $this->quote($c),
            ),
            $coalesceColumns,
        ));

        return sprintf(
            'INSERT INTO %s %s VALUES %s ON CONFLICT %s DO UPDATE SET %s',
            $qTable,
            $cols,
            $values,
            $conflict,
            $assignments,
        );
    }

    protected function buildInsertIfMissingSql(string $table, array $columns, array $conflictColumns): string
    {
        $qTable = $this->quote($table);
        $cols = $this->quotedColumnList($columns);
        $values = $this->placeholderList($columns);
        $conflict = $this->quotedColumnList($conflictColumns);

        return sprintf(
            'INSERT INTO %s %s VALUES %s ON CONFLICT %s DO NOTHING',
            $qTable,
            $cols,
            $values,
            $conflict,
        );
    }
}
