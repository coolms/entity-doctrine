<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Schema\Platform;

final class MySQLPlatformSchemaManager extends AbstractPlatformSchemaManager
{
    public function getGeneratedColumnSql(
        string $columnName,
        string $sourceColumn,
        string $fieldName,
        string $phpType,
    ): string {
        return sprintf(
            <<<SQL
                    ALTER TABLE %%s ADD %s %s GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(%s, '$.%s'))) VIRTUAL
                SQL,
            $columnName,
            $this->mapTypeToSql($phpType),
            $sourceColumn,
            $fieldName,
        );
    }

    public function mapTypeToSql(string $phpType): string
    {
        return match ($phpType) {
            'int' => 'INT',
            'bool' => 'TINYINT(1)',
            'float' => 'DOUBLE',
            'money' => 'DECIMAL(19,4)',
            'datetime' => 'DATETIME',
            default => 'VARCHAR(255)',
        };
    }
}
