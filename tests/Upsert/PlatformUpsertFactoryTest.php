<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Tests\Upsert;

use CoolMS\Entity\Doctrine\Upsert\Platform\MariaDBPlatformUpsert;
use CoolMS\Entity\Doctrine\Upsert\Platform\MySQLPlatformUpsert;
use CoolMS\Entity\Doctrine\Upsert\Platform\OraclePlatformUpsert;
use CoolMS\Entity\Doctrine\Upsert\Platform\PostgreSQLPlatformUpsert;
use CoolMS\Entity\Doctrine\Upsert\Platform\SQLitePlatformUpsert;
use CoolMS\Entity\Doctrine\Upsert\Platform\SQLServerPlatformUpsert;
use CoolMS\Entity\Doctrine\Upsert\PlatformUpsertFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PlatformUpsertFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{0: AbstractPlatform, 1: class-string<\CoolMS\Entity\Doctrine\Upsert\PlatformUpsertInterface>}>
     */
    public static function platformDispatchCases(): iterable
    {
        // MariaDBPlatform must resolve to its OWN impl (not MySQL),
        // because the factory's `match (true)` order must test
        // MariaDB before MySQL (MariaDBPlatform extends MySQLPlatform
        // in DBAL 4.x).
        yield 'mariadb resolves before mysql' => [new MariaDBPlatform(), MariaDBPlatformUpsert::class];
        yield 'mysql' => [new MySQLPlatform(), MySQLPlatformUpsert::class];
        yield 'postgres' => [new PostgreSQLPlatform(), PostgreSQLPlatformUpsert::class];
        yield 'sqlite' => [new SQLitePlatform(), SQLitePlatformUpsert::class];
        yield 'sql-server' => [new SQLServerPlatform(), SQLServerPlatformUpsert::class];
        yield 'oracle' => [new OraclePlatform(), OraclePlatformUpsert::class];
    }

    /**
     * @param class-string<\CoolMS\Entity\Doctrine\Upsert\PlatformUpsertInterface> $expected
     */
    #[DataProvider('platformDispatchCases')]
    #[Test]
    public function createDispatchesToTheExpectedImpl(AbstractPlatform $platform, string $expected): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        $impl = new PlatformUpsertFactory()->create($connection);
        self::assertInstanceOf($expected, $impl);
    }

    #[Test]
    public function createRejectsUnknownPlatform(): void
    {
        // Doctrine's AbstractPlatform has dozens of abstract methods --
        // PHPUnit's createStub() satisfies all of them with no-op
        // implementations, which is enough for the factory's
        // `instanceof` chain. No real query path is exercised.
        $platform = $this->createStub(AbstractPlatform::class);
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported database platform');

        new PlatformUpsertFactory()->create($connection);
    }
}
