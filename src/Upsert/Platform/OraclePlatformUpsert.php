<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Upsert\Platform;

/**
 * Oracle: `MERGE INTO ... USING (SELECT ? AS col, ... FROM DUAL) s ON ...
 *  WHEN MATCHED THEN UPDATE SET ...
 *  WHEN NOT MATCHED THEN INSERT (...) VALUES (...)`.
 *
 * Differences from SQL Server's MERGE:
 *  - No `AS` keywords in the source/target aliases.
 *  - No terminating semicolon (Doctrine DBAL strips it anyway, but
 *    Oracle's PL/SQL grammar would treat it as a block terminator).
 *  - `VALUES (?, ?)` cannot stand alone -- the source must be a
 *    `SELECT ... FROM DUAL` so Oracle has a known row shape.
 *  - Each source column gets an explicit `AS <col>` alias so the ON
 *    and SET clauses can reference it by name.
 *
 * UNTESTED IN CI: the project ships against PostgreSQL only.
 */
final class OraclePlatformUpsert extends AbstractPlatformUpsert
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
        $sourceSelect = implode(', ', array_map(
            fn (string $c): string => sprintf(':%s AS %s', $c, $this->quote($c)),
            $columns,
        ));
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
            'MERGE INTO %s t USING (SELECT %s FROM DUAL) s ON (%s)%s WHEN NOT MATCHED THEN INSERT %s VALUES %s',
            $qTable,
            $sourceSelect,
            $onClause,
            $whenMatched,
            $insertCols,
            $insertValues,
        );
    }
}
