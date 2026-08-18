<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Schema\Platform;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use RuntimeException;

final class PlatformSchemaManagerFactory implements PlatformSchemaManagerFactoryInterface
{
    /**
     * Creates the correct PlatformSchemaManagerInterface for the given DBAL connection.
     *
     * IMPORTANT: MariaDBPlatform extends MySQLPlatform in Doctrine DBAL 4.x,
     * so MariaDB MUST be matched before MySQL.
     *
     * @throws RuntimeException if the database platform is not supported
     * @throws Exception
     */
    public function create(Connection $connection): PlatformSchemaManagerInterface
    {
        $platform = $connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof MariaDBPlatform => new MariaDBPlatformSchemaManager(),
            $platform instanceof MySQLPlatform => new MySQLPlatformSchemaManager(),
            $platform instanceof PostgreSQLPlatform => new PostgreSQLPlatformSchemaManager(),
            $platform instanceof SQLitePlatform => new SQLitePlatformSchemaManager(),
            $platform instanceof SQLServerPlatform => new SQLServerPlatformSchemaManager(),
            $platform instanceof OraclePlatform => new OraclePlatformSchemaManager(),
            default => throw new RuntimeException(sprintf('Unsupported database platform "%s". Supported platforms: MySQL, MariaDB, PostgreSQL, SQLite, SQL Server, Oracle.', $platform::class)),
        };
    }
}
