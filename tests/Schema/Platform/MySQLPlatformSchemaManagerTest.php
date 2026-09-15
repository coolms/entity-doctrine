<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Tests\Schema\Platform;

use CoolMS\Entity\Doctrine\Schema\Platform\MySQLPlatformSchemaManager;
use PHPUnit\Framework\TestCase;

class MySQLPlatformSchemaManagerTest extends TestCase
{
    private MySQLPlatformSchemaManager $manager;

    public function testSupportsVirtualColumns(): void
    {
        $this->assertTrue($this->manager->supportsVirtualColumns());
    }

    public function testSupportsIndexOnVirtualColumns(): void
    {
        $this->assertTrue($this->manager->supportsIndexOnVirtualColumns());
    }

    public function testGeneratedColumnSqlContainsJsonUnquoteAndJsonExtract(): void
    {
        $sql = $this->manager->getGeneratedColumnSql('v_price', 'custom_fields', 'price', 'string');
        $this->assertStringContainsString('JSON_UNQUOTE', $sql);
        $this->assertStringContainsString('JSON_EXTRACT', $sql);
    }

    public function testGeneratedColumnSqlContainsVirtual(): void
    {
        $sql = $this->manager->getGeneratedColumnSql('v_price', 'custom_fields', 'price', 'string');
        $this->assertStringContainsString('VIRTUAL', $sql);
    }

    public function testGeneratedColumnSqlContainsColumnName(): void
    {
        $sql = $this->manager->getGeneratedColumnSql('v_price', 'custom_fields', 'price', 'string');
        $this->assertStringContainsString('v_price', $sql);
    }

    public function testGeneratedColumnSqlContainsFieldName(): void
    {
        $sql = $this->manager->getGeneratedColumnSql('v_price', 'custom_fields', 'price', 'string');
        $this->assertStringContainsString('price', $sql);
    }

    // mapTypeToSql

    public function testMapTypeIntToInt(): void
    {
        $this->assertSame('INT', $this->manager->mapTypeToSql('int'));
    }

    public function testMapTypeBoolToTinyInt(): void
    {
        $this->assertSame('TINYINT(1)', $this->manager->mapTypeToSql('bool'));
    }

    public function testMapTypeFloatToDouble(): void
    {
        $this->assertSame('DOUBLE', $this->manager->mapTypeToSql('float'));
    }

    public function testMapTypeDatetimeToDatetime(): void
    {
        $this->assertSame('DATETIME', $this->manager->mapTypeToSql('datetime'));
    }

    /** Fixed-point, and distinct from `float` -- see the PostgreSQL twin. */
    public function testMapTypeMoneyToFixedPointNotFloat(): void
    {
        $this->assertSame('DECIMAL(19,4)', $this->manager->mapTypeToSql('money'));
        $this->assertNotSame(
            $this->manager->mapTypeToSql('float'),
            $this->manager->mapTypeToSql('money'),
        );
    }

    public function testMapTypeDefaultToVarchar(): void
    {
        $this->assertSame('VARCHAR(255)', $this->manager->mapTypeToSql('string'));
        $this->assertSame('VARCHAR(255)', $this->manager->mapTypeToSql('unknown'));
    }

    // getIndexSql (inherited from AbstractPlatformSchemaManager)

    public function testIndexSqlFormat(): void
    {
        $sql = $this->manager->getIndexSql('products', 'v_price', 'price');
        $this->assertSame('CREATE INDEX idx_products_price ON products (v_price)', trim($sql));
    }

    protected function setUp(): void
    {
        $this->manager = new MySQLPlatformSchemaManager();
    }
}
