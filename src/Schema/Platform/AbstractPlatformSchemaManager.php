<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Schema\Platform;

use function in_array;
use function sprintf;

abstract class AbstractPlatformSchemaManager implements PlatformSchemaManagerInterface
{
    public function supportsVirtualColumns(): bool
    {
        return true;
    }

    public function supportsIndexOnVirtualColumns(): bool
    {
        return true;
    }

    /**
     * The standard form, understood by every platform this project targets.
     * Override where a platform needs `IF EXISTS`, `CASCADE` or its own keyword.
     */
    public function getDropColumnSql(string $tableName, string $columnName): string
    {
        return sprintf('ALTER TABLE %s DROP COLUMN %s', $tableName, $columnName);
    }

    /**
     * Default: the normalised type names the ORM reports for the SQL types
     * {@see mapTypeToSql()} declares. Text-shaped SQL introspects as `text` on
     * some platforms and `string` on others, so BOTH are accepted for the
     * text-mapped types rather than each platform restating the same pair.
     *
     * A platform whose `mapTypeToSql()` routes a type somewhere unusual must
     * override — PostgreSQL does, because its `datetime` is TEXT.
     */
    public function matchesDeclaredType(string $introspectedType, string $phpType): bool
    {
        $expected = match ($phpType) {
            'int' => ['integer'],
            'bool' => ['boolean'],
            'float' => ['float'],
            'money' => ['decimal'],
            'datetime' => ['datetime'],
            default => ['text', 'string'],
        };

        return in_array($introspectedType, $expected, true);
    }

    public function getIndexSql(string $tableName, string $columnName, string $fieldName): string
    {
        return sprintf(
            <<<SQL
                    CREATE INDEX idx_%s_%s ON %s (%s)
                SQL,
            $tableName,
            $fieldName,
            $tableName,
            $columnName,
        );
    }

    /**
     * Default: no functional CI index. Platforms whose default collation is already
     * case-insensitive (MySQL, MariaDB, SQLite) need nothing extra; PostgreSQL
     * overrides this to emit LOWER()-wrapped indexes.
     */
    public function getCaseInsensitiveIndexSql(
        string $tableName,
        string $columnName,
        string $fieldName,
        string $phpType,
    ): ?string {
        return null;
    }

    /**
     * PostgreSQL/SQLite-compatible DROP. MySQL/MariaDB/Oracle/SQL Server need
     * different syntax (table-qualified, no/limited IF EXISTS) and should override.
     */
    public function getDropIndexSql(string $tableName, string $fieldName): string
    {
        return sprintf('DROP INDEX IF EXISTS idx_%s_%s', $tableName, $fieldName);
    }

    /**
     * Default: no CI index to drop, mirrors getCaseInsensitiveIndexSql() returning null.
     */
    public function getDropCaseInsensitiveIndexSql(string $tableName, string $fieldName): ?string
    {
        return null;
    }
}
