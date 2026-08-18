<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Upsert\Platform;

/**
 * SQL Server: `MERGE INTO ... USING (VALUES ...) ON ... WHEN MATCHED THEN UPDATE
 * WHEN NOT MATCHED THEN INSERT;`.
 *
 * The MERGE syntax requires a terminating semicolon (T-SQL parser
 * quirk -- omitting it raises "MERGE must terminate with a semicolon").
 *
 * Insert-if-missing reuses the MERGE template with the `WHEN MATCHED`
 * branch dropped. Affected-row count from MERGE is the merged-row count
 * (insert + update); for the insert-if-missing case the caller should
 * treat it as advisory.
 *
 * UNTESTED IN CI: the project ships against PostgreSQL only.
 */
final class SQLServerPlatformUpsert extends AbstractPlatformUpsert
{
    protected function buildUpsertSql(string $table, array $columns, array $conflictColumns, array $updateColumns): string
    {
        return $this->mergeTemplate(
            table: $table,
            columns: $columns,
            conflictColumns: $conflictColumns,
            updateAssignments: array_map(
                fn (string $c): string => sprintf('t.%s = s.%s', $this->quote($c), $this->quote($c)),
                $updateColumns,
            ),
        );
    }

    protected function buildUpsertCoalesceSql(string $table, array $columns, array $conflictColumns, array $coalesceColumns): string
    {
        return $this->mergeTemplate(
            table: $table,
            columns: $columns,
            conflictColumns: $conflictColumns,
            updateAssignments: array_map(
                fn (string $c): string => sprintf(
                    't.%s = COALESCE(s.%s, t.%s)',
                    $this->quote($c),
                    $this->quote($c),
                    $this->quote($c),
                ),
                $coalesceColumns,
            ),
        );
    }

    protected function buildInsertIfMissingSql(string $table, array $columns, array $conflictColumns): string
    {
        return $this->mergeTemplate(
            table: $table,
            columns: $columns,
            conflictColumns: $conflictColumns,
            updateAssignments: null,
        );
    }

    /**
     * @param list<string>  $columns
     * @param list<string>  $conflictColumns
     * @param ?list<string> $updateAssignments rendered `t.col = ...` strings; null skips WHEN MATCHED
     */
    private function mergeTemplate(
        string $table,
        array $columns,
        array $conflictColumns,
        ?array $updateAssignments,
    ): string {
        $qTable = $this->quote($table);
        $sourceCols = $this->quotedColumnList($columns);
        $values = $this->placeholderList($columns);
        $onClause = implode(' AND ', array_map(
            fn (string $c): string => sprintf('t.%s = s.%s', $this->quote($c), $this->quote($c)),
            $conflictColumns,
        ));
        $insertCols = $this->quotedColumnList($columns);
        $insertValues = '(' . implode(', ', array_map(
            fn (string $c): string => 's.' . $this->quote($c),
            $columns,
        )) . ')';

        $whenMatched = (null === $updateAssignments)
            ? ''
            : sprintf(' WHEN MATCHED THEN UPDATE SET %s', implode(', ', $updateAssignments));

        return sprintf(
            'MERGE INTO %s AS t USING (VALUES %s) AS s %s ON (%s)%s WHEN NOT MATCHED THEN INSERT %s VALUES %s;',
            $qTable,
            $values,
            $sourceCols,
            $onClause,
            $whenMatched,
            $insertCols,
            $insertValues,
        );
    }
}
