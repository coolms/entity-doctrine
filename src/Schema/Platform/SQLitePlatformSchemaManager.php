<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Schema\Platform;

/**
 * SQLite does not support generated columns or indexes on them.
 * The DynamicSchemaManager will skip DDL when these return false.
 */
final class SQLitePlatformSchemaManager extends AbstractPlatformSchemaManager
{
    public function supportsVirtualColumns(): bool
    {
        return false;
    }

    public function supportsIndexOnVirtualColumns(): bool
    {
        return false;
    }

    public function getGeneratedColumnSql(
        string $columnName,
        string $sourceColumn,
        string $fieldName,
        string $phpType,
    ): string {
        return '';
    }

    public function mapTypeToSql(string $phpType): string
    {
        return match ($phpType) {
            'int', 'bool' => 'INTEGER',
            'float' => 'REAL',
            'money' => 'NUMERIC(19,4)',
            'datetime' => 'TEXT',
            default => 'TEXT',
        };
    }
}
