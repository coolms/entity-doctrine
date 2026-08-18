<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Tests\Upsert\Platform;

use CoolMS\Entity\Doctrine\Upsert\Platform\AbstractPlatformUpsert;
use CoolMS\Entity\Doctrine\Upsert\Platform\MariaDBPlatformUpsert;
use CoolMS\Entity\Doctrine\Upsert\Platform\MySQLPlatformUpsert;
use CoolMS\Entity\Doctrine\Upsert\Platform\OraclePlatformUpsert;
use CoolMS\Entity\Doctrine\Upsert\Platform\PostgreSQLPlatformUpsert;
use CoolMS\Entity\Doctrine\Upsert\Platform\SQLitePlatformUpsert;
use CoolMS\Entity\Doctrine\Upsert\Platform\SQLServerPlatformUpsert;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DQL Ship B -- assert the generated SQL string per platform for the
 * canonical Notification user-state row shape:
 *
 *   table: coolms_notification_user_states
 *   data:  notification_id, user_id, read_at, dismissed_at
 *   conflict: (notification_id, user_id)
 *
 * Tests use `Connection::executeStatement` as the capture point so the
 * SQL flows through the same code path as production. No DB is reached.
 */
final class PlatformUpsertSqlTest extends TestCase
{
    private const TABLE = 'coolms_notification_user_states';

    private const ROW = [
        'notification_id' => 'n',
        'user_id' => 'u',
        'read_at' => null,
        'dismissed_at' => null,
    ];

    private const CONFLICT = ['notification_id', 'user_id'];

    #[Test]
    public function postgresUpsertGeneratesOnConflictDoUpdateSet(): void
    {
        $sql = $this->captureUpsertSql(new PostgreSQLPlatform());
        self::assertSame(
            'INSERT INTO "coolms_notification_user_states" ("notification_id", "user_id", "read_at", "dismissed_at") '
            . 'VALUES (:notification_id, :user_id, :read_at, :dismissed_at) '
            . 'ON CONFLICT ("notification_id", "user_id") DO UPDATE SET "read_at" = EXCLUDED."read_at", "dismissed_at" = EXCLUDED."dismissed_at"',
            $sql,
        );
    }

    #[Test]
    public function postgresUpsertCoalesceQualifiesExistingColumnWithTableName(): void
    {
        $sql = $this->captureCoalesceSql(new PostgreSQLPlatform());
        self::assertStringContainsString(
            '"read_at" = COALESCE(EXCLUDED."read_at", "coolms_notification_user_states"."read_at")',
            $sql,
        );
        self::assertStringContainsString(
            '"dismissed_at" = COALESCE(EXCLUDED."dismissed_at", "coolms_notification_user_states"."dismissed_at")',
            $sql,
        );
    }

    #[Test]
    public function postgresInsertIfMissingEmitsDoNothing(): void
    {
        $sql = $this->captureInsertIfMissingSql(new PostgreSQLPlatform());
        self::assertStringContainsString('ON CONFLICT ("notification_id", "user_id") DO NOTHING', $sql);
    }

    #[Test]
    public function sqliteSharesPostgresOnConflictTemplate(): void
    {
        // SQLite extends the Postgres impl; the generated SQL is the
        // same template (`excluded` lower-case is the canonical form,
        // but identifier quoting on SQLite still uses double-quotes).
        $sql = $this->captureUpsertSql(new SQLitePlatform());
        self::assertStringContainsString('ON CONFLICT ("notification_id", "user_id") DO UPDATE SET', $sql);
        self::assertStringContainsString('"read_at" = EXCLUDED."read_at"', $sql);
    }

    #[Test]
    public function mysqlGeneratesOnDuplicateKeyUpdateWithValues(): void
    {
        $sql = $this->captureUpsertSql(new MySQLPlatform());
        self::assertSame(
            'INSERT INTO `coolms_notification_user_states` (`notification_id`, `user_id`, `read_at`, `dismissed_at`) '
            . 'VALUES (:notification_id, :user_id, :read_at, :dismissed_at) '
            . 'ON DUPLICATE KEY UPDATE `read_at` = VALUES(`read_at`), `dismissed_at` = VALUES(`dismissed_at`)',
            $sql,
        );
    }

    #[Test]
    public function mysqlInsertIfMissingUsesInsertIgnore(): void
    {
        $sql = $this->captureInsertIfMissingSql(new MySQLPlatform());
        self::assertStringStartsWith('INSERT IGNORE INTO `coolms_notification_user_states`', $sql);
        self::assertStringNotContainsString('ON DUPLICATE KEY', $sql);
    }

    #[Test]
    public function mariadbReusesMysqlTemplate(): void
    {
        $sql = $this->captureUpsertSql(new MariaDBPlatform());
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        self::assertStringContainsString('`read_at` = VALUES(`read_at`)', $sql);
    }

    #[Test]
    public function sqlServerGeneratesMergeWithTrailingSemicolon(): void
    {
        $sql = $this->captureUpsertSql(new SQLServerPlatform());
        self::assertStringStartsWith('MERGE INTO [coolms_notification_user_states] AS t USING (VALUES', $sql);
        self::assertStringContainsString('WHEN MATCHED THEN UPDATE SET', $sql);
        self::assertStringContainsString('WHEN NOT MATCHED THEN INSERT', $sql);
        self::assertStringEndsWith(';', $sql);
    }

    #[Test]
    public function sqlServerInsertIfMissingOmitsWhenMatched(): void
    {
        $sql = $this->captureInsertIfMissingSql(new SQLServerPlatform());
        self::assertStringNotContainsString('WHEN MATCHED', $sql);
        self::assertStringContainsString('WHEN NOT MATCHED THEN INSERT', $sql);
    }

    #[Test]
    public function oracleGeneratesMergeFromDualWithAliasedColumns(): void
    {
        $sql = $this->captureUpsertSql(new OraclePlatform());
        self::assertStringStartsWith('MERGE INTO "coolms_notification_user_states" t USING (SELECT', $sql);
        self::assertStringContainsString('FROM DUAL', $sql);
        // Oracle uses :placeholder AS "col" so the source row has named columns.
        self::assertStringContainsString(':notification_id AS "notification_id"', $sql);
        self::assertStringContainsString('WHEN MATCHED THEN UPDATE SET', $sql);
        self::assertStringContainsString('WHEN NOT MATCHED THEN INSERT', $sql);
        // Oracle MERGE must NOT carry a terminating semicolon.
        self::assertFalse(str_ends_with($sql, ';'));
    }

    #[Test]
    public function oracleCoalesceWrapsTargetWithSourceAlias(): void
    {
        $sql = $this->captureCoalesceSql(new OraclePlatform());
        self::assertStringContainsString('t."read_at" = COALESCE(s."read_at", t."read_at")', $sql);
        self::assertStringContainsString('t."dismissed_at" = COALESCE(s."dismissed_at", t."dismissed_at")', $sql);
    }

    private function captureUpsertSql(AbstractPlatform $platform): string
    {
        $impl = $this->makeImplFor($platform);
        $captured = '';
        $connection = $this->connectionThatCaptures($platform, $captured);
        // Force the impl to use the capturing connection by reconstructing
        // it -- the makeImplFor() builder already passes this connection,
        // so nothing more to wire here.
        unset($impl);
        $impl = $this->makeImplFor($platform, $connection);
        $impl->upsert(self::TABLE, self::ROW, self::CONFLICT, ['read_at', 'dismissed_at']);

        return $captured;
    }

    private function captureCoalesceSql(AbstractPlatform $platform): string
    {
        $captured = '';
        $connection = $this->connectionThatCaptures($platform, $captured);
        $impl = $this->makeImplFor($platform, $connection);
        $impl->upsertCoalesce(self::TABLE, self::ROW, self::CONFLICT, ['read_at', 'dismissed_at']);

        return $captured;
    }

    private function captureInsertIfMissingSql(AbstractPlatform $platform): string
    {
        $captured = '';
        $connection = $this->connectionThatCaptures($platform, $captured);
        $impl = $this->makeImplFor($platform, $connection);
        $impl->insertIfMissing(self::TABLE, self::ROW, self::CONFLICT);

        return $captured;
    }

    private function connectionThatCaptures(AbstractPlatform $platform, string &$captured): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$captured): int {
                $captured = $sql;

                return 1;
            },
        );

        return $connection;
    }

    private function makeImplFor(AbstractPlatform $platform, ?Connection $connection = null): AbstractPlatformUpsert
    {
        $connection ??= $this->createStub(Connection::class);

        return match (true) {
            $platform instanceof MariaDBPlatform => new MariaDBPlatformUpsert($connection),
            $platform instanceof MySQLPlatform => new MySQLPlatformUpsert($connection),
            $platform instanceof PostgreSQLPlatform => new PostgreSQLPlatformUpsert($connection),
            $platform instanceof SQLitePlatform => new SQLitePlatformUpsert($connection),
            $platform instanceof SQLServerPlatform => new SQLServerPlatformUpsert($connection),
            $platform instanceof OraclePlatform => new OraclePlatformUpsert($connection),
            default => throw new LogicException('Unsupported platform in test fixture: ' . $platform::class),
        };
    }
}
