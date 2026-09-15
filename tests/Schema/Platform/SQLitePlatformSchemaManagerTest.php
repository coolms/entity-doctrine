<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Tests\Schema\Platform;

use CoolMS\Entity\Doctrine\Schema\Platform\SQLitePlatformSchemaManager;
use PHPUnit\Framework\TestCase;

class SQLitePlatformSchemaManagerTest extends TestCase
{
    private SQLitePlatformSchemaManager $manager;

    public function testDoesNotSupportVirtualColumns(): void
    {
        $this->assertFalse($this->manager->supportsVirtualColumns());
    }

    public function testDoesNotSupportIndexOnVirtualColumns(): void
    {
        $this->assertFalse($this->manager->supportsIndexOnVirtualColumns());
    }

    public function testGetGeneratedColumnSqlReturnsEmptyString(): void
    {
        $sql = $this->manager->getGeneratedColumnSql('v_price', 'custom_fields', 'price', 'string');
        $this->assertSame('', $sql);
    }

    public function testMapTypeIntToInteger(): void
    {
        $this->assertSame('INTEGER', $this->manager->mapTypeToSql('int'));
    }

    public function testMapTypeBoolToInteger(): void
    {
        $this->assertSame('INTEGER', $this->manager->mapTypeToSql('bool'));
    }

    public function testMapTypeFloatToReal(): void
    {
        $this->assertSame('REAL', $this->manager->mapTypeToSql('float'));
    }

    public function testMapTypeDatetimeToText(): void
    {
        $this->assertSame('TEXT', $this->manager->mapTypeToSql('datetime'));
    }

    /**
     * SQLite has type AFFINITY rather than strict types, so this buys less
     * here than elsewhere -- but it must still not collapse to REAL, which is
     * the float affinity the money type exists to avoid.
     */
    public function testMapTypeMoneyToFixedPointNotFloat(): void
    {
        $this->assertSame('NUMERIC(19,4)', $this->manager->mapTypeToSql('money'));
        $this->assertNotSame(
            $this->manager->mapTypeToSql('float'),
            $this->manager->mapTypeToSql('money'),
        );
    }

    public function testMapTypeDefaultToText(): void
    {
        $this->assertSame('TEXT', $this->manager->mapTypeToSql('string'));
    }

    protected function setUp(): void
    {
        $this->manager = new SQLitePlatformSchemaManager();
    }
}
