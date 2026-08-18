<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Schema\Platform;

/**
 * PostgreSQL only supports STORED generated columns (not VIRTUAL).
 * JSON extraction uses the ->> operator, which returns TEXT.
 * A CAST is applied so the column has the correct type.
 */
final class PostgreSQLPlatformSchemaManager extends AbstractPlatformSchemaManager
{
    public function getGeneratedColumnSql(
        string $columnName,
        string $sourceColumn,
        string $fieldName,
        string $phpType,
    ): string {
        $sqlType = $this->mapTypeToSql($phpType);

        return sprintf(
            <<<SQL
                    ALTER TABLE %%s ADD %s %s GENERATED ALWAYS AS (CAST((%s->>'%s') AS %s)) STORED
                SQL,
            $columnName,
            $sqlType,
            $sourceColumn,
            $fieldName,
            $sqlType,
        );
    }

    /**
     * ## `datetime` is TEXT here, and PostgreSQL leaves no choice.
     *
     * A STORED generated column's expression must be IMMUTABLE, and PostgreSQL
     * has no immutable way to parse text into a date or timestamp: every route
     * reads the `DateStyle` GUC, so all of them are merely STABLE. Measured
     * against PostgreSQL 16 on a scratch table — all four rejected with
     * `generation expression is not immutable`:
     *
     *     CAST(extras->>'d' AS TIMESTAMP)          REJECTED
     *     CAST(extras->>'d' AS DATE)               REJECTED
     *     to_date(extras->>'d', 'YYYY-MM-DD')      REJECTED
     *     to_timestamp(extras->>'d', 'YYYY-MM-DD') REJECTED
     *
     * while `INTEGER`, `BOOLEAN`, `NUMERIC(19,4)` and `DOUBLE PRECISION` were
     * all accepted. So this is not a preference — declaring TIMESTAMP makes the
     * DDL fail outright, which is how it was found: the schema sync that fixed
     * the boolean columns reported `publishDate: generation expression is not
     * immutable` and rolled its rebuild back.
     *
     * TEXT is survivable rather than merely a defeat, because `extras` holds
     * ISO-8601 and ISO-8601 sorts chronologically as text — that ordering
     * property is the reason the format was chosen. What it does NOT give is
     * typed range filtering: an RQL comparison on a date field is a string
     * comparison, so it is only correct for values of uniform ISO precision.
     *
     * The upgrade path, if typed date filtering is ever needed, is an
     * `IMMUTABLE` SQL wrapper around the cast, installed by migration. It is
     * deliberately NOT taken here: it asserts an immutability PostgreSQL just
     * denied, so a `DateStyle` change would silently invalidate both the stored
     * values and any index built over them.
     *
     * Only PostgreSQL is constrained this way. MySQL and MariaDB keep DATETIME —
     * their generated columns require determinism, not immutability.
     */
    public function mapTypeToSql(string $phpType): string
    {
        return match ($phpType) {
            'int' => 'INTEGER',
            'bool' => 'BOOLEAN',
            'float' => 'DOUBLE PRECISION',
            'money' => 'NUMERIC(19,4)',
            default => 'TEXT',
        };
    }

    /**
     * PostgreSQL's `datetime` is TEXT — see {@see mapTypeToSql()} — so a
     * timestamp field's column introspects as `text` and the inherited answer,
     * which expects `datetime`, would call every date field diverged and
     * rebuild its column on every save. That rebuild then fails, because the
     * temporal generated column is exactly what this platform refuses.
     */
    public function matchesDeclaredType(string $introspectedType, string $phpType): bool
    {
        if ('datetime' === $phpType) {
            return in_array($introspectedType, ['text', 'string'], true);
        }

        return parent::matchesDeclaredType($introspectedType, $phpType);
    }

    /**
     * PostgreSQL planner uses an index on a generated column only when the WHERE
     * expression matches the column's source byte-for-byte. Case-insensitive LIKE
     * filters (e.g., LOWER(extras->>'title') LIKE ...) need a paired functional
     * index on LOWER(v_field) to be picked. CI applies only to text-mapped fields.
     */
    public function getCaseInsensitiveIndexSql(
        string $tableName,
        string $columnName,
        string $fieldName,
        string $phpType,
    ): ?string {
        // `datetime` reaches TEXT only because PostgreSQL cannot build a
        // temporal generated column at all (see mapTypeToSql), not because the
        // value is prose. An ISO-8601 timestamp has no case to fold, so a
        // LOWER()-wrapped index over it would cost writes and serve nothing.
        if ('datetime' === $phpType) {
            return null;
        }

        if ('TEXT' !== $this->mapTypeToSql($phpType)) {
            return null;
        }

        return sprintf(
            'CREATE INDEX IF NOT EXISTS idx_%s_%s_ci ON %s (LOWER(%s))',
            $tableName,
            $fieldName,
            $tableName,
            $columnName,
        );
    }

    public function getDropCaseInsensitiveIndexSql(string $tableName, string $fieldName): string
    {
        return sprintf('DROP INDEX IF EXISTS idx_%s_%s_ci', $tableName, $fieldName);
    }
}
