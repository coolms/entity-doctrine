<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Upsert\Platform;

/**
 * MySQL: `INSERT ... ON DUPLICATE KEY UPDATE col = VALUES(col)`.
 *
 * - Conflict columns are implicit: the dialect triggers ON DUPLICATE KEY
 *   for any unique/PK constraint violation. The $conflictColumns
 *   argument is therefore unused in the SQL string (the caller still
 *   declares it to keep the cross-platform contract).
 * - `VALUES(col)` references the row that would have been inserted.
 *   Deprecated as of MySQL 8.0.20 in favour of the row-alias form,
 *   but still supported in 9.x. The row-alias rewrite is a future
 *   tightening if the project ever pins MySQL >= 8.0.20.
 * - `INSERT IGNORE` is the dialect's insert-if-missing; it suppresses
 *   the duplicate-key error rather than skipping the row explicitly.
 *
 * UNTESTED IN CI: the project ships against PostgreSQL only. This
 * template follows the published MySQL reference; flag any downstream
 * issue back through the DQL Ship B follow-ups.
 */
class MySQLPlatformUpsert extends AbstractPlatformUpsert
{
    protected function buildUpsertSql(string $table, array $columns, array $conflictColumns, array $updateColumns): string
    {
        $qTable = $this->quote($table);
        $cols = $this->quotedColumnList($columns);
        $values = $this->placeholderList($columns);
        $assignments = implode(', ', array_map(
            fn (string $c): string => sprintf('%s = VALUES(%s)', $this->quote($c), $this->quote($c)),
            $updateColumns,
        ));

        return sprintf(
            'INSERT INTO %s %s VALUES %s ON DUPLICATE KEY UPDATE %s',
            $qTable,
            $cols,
            $values,
            $assignments,
        );
    }

    protected function buildUpsertCoalesceSql(string $table, array $columns, array $conflictColumns, array $coalesceColumns): string
    {
        $qTable = $this->quote($table);
        $cols = $this->quotedColumnList($columns);
        $values = $this->placeholderList($columns);
        $assignments = implode(', ', array_map(
            fn (string $c): string => sprintf(
                '%s = COALESCE(VALUES(%s), %s.%s)',
                $this->quote($c),
                $this->quote($c),
                $qTable,
                $this->quote($c),
            ),
            $coalesceColumns,
        ));

        return sprintf(
            'INSERT INTO %s %s VALUES %s ON DUPLICATE KEY UPDATE %s',
            $qTable,
            $cols,
            $values,
            $assignments,
        );
    }

    protected function buildInsertIfMissingSql(string $table, array $columns, array $conflictColumns): string
    {
        $qTable = $this->quote($table);
        $cols = $this->quotedColumnList($columns);
        $values = $this->placeholderList($columns);

        return sprintf(
            'INSERT IGNORE INTO %s %s VALUES %s',
            $qTable,
            $cols,
            $values,
        );
    }
}
