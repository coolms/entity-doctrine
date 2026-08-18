<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Schema\Platform;

interface PlatformSchemaManagerInterface
{
    /**
     * Whether the platform supports generated (virtual/stored) columns.
     */
    public function supportsVirtualColumns(): bool;

    /**
     * Whether the platform supports an index on a generated column.
     * Some platforms (e.g., SQLite) do not support either feature.
     */
    public function supportsIndexOnVirtualColumns(): bool;

    /**
     * Returns the DDL fragment for a generated column definition.
     *
     * Example MySQL result:
     *   `v_price` VARCHAR(255) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(extras, '$.price'))) VIRTUAL
     *
     * @param string $columnName   The generated column name (e.g., "v_price")
     * @param string $sourceColumn The JSON source column (e.g., "extras")
     * @param string $fieldName    The JSON key to extract (e.g., "price")
     * @param string $phpType      The PHP type of the field (e.g., "int", "bool", "string")
     */
    public function getGeneratedColumnSql(
        string $columnName,
        string $sourceColumn,
        string $fieldName,
        string $phpType,
    ): string;

    /**
     * Returns the DDL for creating an index on the generated column.
     *
     * Example result:
     *   CREATE INDEX idx_my_table_price ON my_table (v_price)
     *
     * @param string $tableName  The table that owns the column
     * @param string $columnName The generated column name
     * @param string $fieldName  Used to build a unique index name
     */
    public function getIndexSql(string $tableName, string $columnName, string $fieldName): string;

    /**
     * Returns the DDL for a paired functional index on LOWER($columnName), or null
     * when the platform does not need it (e.g., default case-insensitive collations
     * on MySQL/MariaDB/SQLite). Implementations MUST emit idempotent SQL so that
     * re-running schema:sync against a column whose CI index already exists is a no-op.
     *
     * Only meaningful for text-like fields; the platform decides which $phpType values
     * qualify (typically those that mapTypeToSql() routes to TEXT/VARCHAR).
     *
     * @param string $tableName  The table that owns the column
     * @param string $columnName The generated column name (e.g., "v_title")
     * @param string $fieldName  Used to build a unique index name
     * @param string $phpType    The FieldDefinition type ("text", "select", "int", ...)
     */
    public function getCaseInsensitiveIndexSql(
        string $tableName,
        string $columnName,
        string $fieldName,
        string $phpType,
    ): ?string;

    /**
     * Returns the DDL for dropping a generated column, so a field whose type
     * changed can have its column rebuilt.
     *
     * Here rather than inline at the caller for the same reason every other
     * statement on this interface is: the caller must not spell SQL. The
     * `ALTER TABLE x DROP COLUMN y` form is standard and lives in
     * {@see AbstractPlatformSchemaManager}, but a platform that needs `IF
     * EXISTS`, a `CASCADE`, or a different keyword can say so without the
     * caller learning which platform it is talking to.
     *
     * @param string $tableName  The table that owns the column
     * @param string $columnName The generated column name (e.g., "v_price")
     */
    public function getDropColumnSql(string $tableName, string $columnName): string;

    /**
     * Whether a column already IN the database has the type this platform would
     * declare for `$phpType` — i.e. whether a rebuild is unnecessary.
     *
     * `$introspectedType` is the ORM's normalised name for the existing
     * column's type (`float`, `text`, `decimal`, …), not raw SQL. Comparing raw
     * SQL text cannot work: this interface answers `INTEGER` where PostgreSQL
     * itself declares `INT`, so a string comparison would call every integer
     * column diverged and rebuild a STORED generated column on every save.
     *
     * The comparison lives HERE because the answer is platform knowledge and
     * nothing else: only this class knows that its `datetime` lands on TEXT, or
     * that its money type introspects as `decimal`. A caller that worked it out
     * for itself would have to know which platform it was on, which is exactly
     * what this seam exists to prevent.
     *
     * @param string $introspectedType the existing column's normalised type name
     * @param string $phpType          a member of `Definition::DATA_TYPES`
     */
    public function matchesDeclaredType(string $introspectedType, string $phpType): bool;

    /**
     * Returns the DDL for dropping the regular index produced by getIndexSql().
     * Used by syncField when a field's filterable/sortable flag flips false so the
     * column survives but its index is removed. SQL MUST be idempotent so a drop
     * against a missing index is a no-op.
     */
    public function getDropIndexSql(string $tableName, string $fieldName): string;

    /**
     * Returns the DDL for dropping the paired functional CI index produced by
     * getCaseInsensitiveIndexSql(), or null when the platform never emits one.
     * SQL MUST be idempotent.
     */
    public function getDropCaseInsensitiveIndexSql(string $tableName, string $fieldName): ?string;

    /**
     * Maps a PHP type string to the platform-specific SQL column type.
     *
     * `$phpType` is always one of the field module's canonical data types, never a
     * widget name. Callers translate the widget word stored on a field definition
     * first —
     * this method must never be handed `checkbox`, `number` or `date`, because
     * its `default` arm would answer TEXT for them and be indistinguishable
     * from a genuine text declaration.
     *
     * ## A platform may decline a type
     *
     * The answer must be something the platform can actually put in a GENERATED
     * column, which is narrower than what it can put in an ordinary one. Where
     * those differ, the generated-column answer wins and the reason is recorded
     * at the implementation: PostgreSQL maps `datetime` to TEXT because it has
     * no immutable text-to-timestamp conversion, and declaring TIMESTAMP makes
     * the DDL fail rather than merely perform badly. Answering TEXT for a type
     * the platform cannot express is therefore correct, not a fallback.
     *
     * ## `money` is exact, `float` is not
     *
     * `money` exists as a separate type because a total stored as `float` is a
     * binary double: it cannot hold 0.1 exactly, and the error compounds over a
     * summed column. Every platform maps it to its own fixed-point type at
     * scale **19,4** — the conventional money shape, and what SQL Server's own
     * MONEY uses. Four decimal places carry currency sub-units and unit rates.
     *
     * The scale is FIXED rather than read from `Definition::$precision`,
     * because this method takes only a type string; threading precision through
     * would change the signature and all six implementations for a value
     * nothing currently sets. Widen it here, in one place, if that changes.
     *
     * Note `money` routes to a non-text type, so
     * {@see getCaseInsensitiveIndexSql()} correctly declines to build a
     * LOWER()-wrapped index for it without needing to know the type exists.
     *
     * @param string $phpType e.g., "int", "bool", "float", "money", "datetime", "string"
     */
    public function mapTypeToSql(string $phpType): string;
}
