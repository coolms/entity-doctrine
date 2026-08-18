<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Schema\Platform;

/**
 * SQL Server uses JSON_VALUE() with AS ... PERSISTED syntax.
 * Generated columns must be PERSISTED to be indexable.
 */
final class SQLServerPlatformSchemaManager extends AbstractPlatformSchemaManager
{
    public function getGeneratedColumnSql(
        string $columnName,
        string $sourceColumn,
        string $fieldName,
        string $phpType,
    ): string {
        return sprintf(
            <<<SQL
                    ALTER TABLE %%s ADD %s AS JSON_VALUE(%s, '$.%s') PERSISTED
                SQL,
            $columnName,
            $sourceColumn,
            $fieldName,
        );
    }

    public function mapTypeToSql(string $phpType): string
    {
        return match ($phpType) {
            'int' => 'INT',
            'bool' => 'BIT',
            'float' => 'FLOAT',
            'money' => 'DECIMAL(19,4)',
            'datetime' => 'DATETIME2',
            default => 'NVARCHAR(255)',
        };
    }
}
