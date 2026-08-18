<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Schema\Platform;

/**
 * Oracle uses JSON_VALUE() with GENERATED ALWAYS AS ... VIRTUAL syntax.
 */
final class OraclePlatformSchemaManager extends AbstractPlatformSchemaManager
{
    public function getGeneratedColumnSql(
        string $columnName,
        string $sourceColumn,
        string $fieldName,
        string $phpType,
    ): string {
        return sprintf(
            <<<SQL
                    ALTER TABLE %%s ADD %s %s GENERATED ALWAYS AS (JSON_VALUE(%s, '$.%s')) VIRTUAL
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
            'int' => 'NUMBER(10)',
            'bool' => 'NUMBER(1)',
            'float' => 'FLOAT',
            'money' => 'NUMBER(19,4)',
            'datetime' => 'TIMESTAMP',
            default => 'VARCHAR2(255)',
        };
    }
}
