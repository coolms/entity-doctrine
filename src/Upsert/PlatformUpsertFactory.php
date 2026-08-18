<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Upsert;

use CoolMS\Entity\Doctrine\Upsert\Platform\MariaDBPlatformUpsert;
use CoolMS\Entity\Doctrine\Upsert\Platform\MySQLPlatformUpsert;
use CoolMS\Entity\Doctrine\Upsert\Platform\OraclePlatformUpsert;
use CoolMS\Entity\Doctrine\Upsert\Platform\PostgreSQLPlatformUpsert;
use CoolMS\Entity\Doctrine\Upsert\Platform\SQLitePlatformUpsert;
use CoolMS\Entity\Doctrine\Upsert\Platform\SQLServerPlatformUpsert;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use RuntimeException;

/**
 * Mirrors {@see \CoolMS\Entity\Doctrine\Schema\Platform\PlatformSchemaManagerFactory}
 * but produces {@see PlatformUpsertInterface} impls. The `match (true)`
 * order matters: `MariaDBPlatform` extends `MySQLPlatform` in DBAL 4.x,
 * so MariaDB MUST be tested first.
 */
final class PlatformUpsertFactory implements PlatformUpsertFactoryInterface
{
    public function create(Connection $connection): PlatformUpsertInterface
    {
        $platform = $connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof MariaDBPlatform => new MariaDBPlatformUpsert($connection),
            $platform instanceof MySQLPlatform => new MySQLPlatformUpsert($connection),
            $platform instanceof PostgreSQLPlatform => new PostgreSQLPlatformUpsert($connection),
            $platform instanceof SQLitePlatform => new SQLitePlatformUpsert($connection),
            $platform instanceof SQLServerPlatform => new SQLServerPlatformUpsert($connection),
            $platform instanceof OraclePlatform => new OraclePlatformUpsert($connection),
            default => throw new RuntimeException(sprintf('Unsupported database platform "%s". Supported platforms: MySQL, MariaDB, PostgreSQL, SQLite, SQL Server, Oracle.', $platform::class)),
        };
    }
}
