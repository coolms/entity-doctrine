<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Tests\Schema\Platform;

use CoolMS\Entity\Doctrine\Schema\Platform\PostgreSQLPlatformSchemaManager;
use PHPUnit\Framework\TestCase;

class PostgreSQLPlatformSchemaManagerTest extends TestCase
{
    private PostgreSQLPlatformSchemaManager $manager;

    public function testSupportsVirtualColumns(): void
    {
        $this->assertTrue($this->manager->supportsVirtualColumns());
    }

    public function testSupportsIndexOnVirtualColumns(): void
    {
        $this->assertTrue($this->manager->supportsIndexOnVirtualColumns());
    }

    public function testGeneratedColumnSqlContainsJsonArrowOperator(): void
    {
        $sql = $this->manager->getGeneratedColumnSql('v_price', 'custom_fields', 'price', 'string');
        $this->assertStringContainsString('->>', $sql);
    }

    public function testGeneratedColumnSqlContainsStored(): void
    {
        $sql = $this->manager->getGeneratedColumnSql('v_price', 'custom_fields', 'price', 'string');
        $this->assertStringContainsString('STORED', $sql);
    }

    public function testGeneratedColumnSqlDoesNotContainVirtual(): void
    {
        $sql = $this->manager->getGeneratedColumnSql('v_price', 'custom_fields', 'price', 'string');
        $this->assertStringNotContainsString('VIRTUAL', $sql);
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

    public function testMapTypeIntToInteger(): void
    {
        $this->assertSame('INTEGER', $this->manager->mapTypeToSql('int'));
    }

    public function testMapTypeBoolToBoolean(): void
    {
        $this->assertSame('BOOLEAN', $this->manager->mapTypeToSql('bool'));
    }

    public function testMapTypeFloatToDoublePrecision(): void
    {
        $this->assertSame('DOUBLE PRECISION', $this->manager->mapTypeToSql('float'));
    }

    /**
     * NOT a preference -- PostgreSQL rejects the alternative. A STORED generated
     * column needs an IMMUTABLE expression, and every text-to-temporal route
     * reads `DateStyle` and so is only STABLE. Measured against PostgreSQL 16:
     * `CAST(x AS TIMESTAMP)`, `CAST(x AS DATE)`, `to_date(x, ...)` and
     * `to_timestamp(x, ...)` are all refused with `generation expression is not
     * immutable`, while the int/bool/numeric casts above are accepted.
     *
     * So declaring TIMESTAMP does not merely index badly, it makes the ALTER
     * TABLE fail. This assertion is what stops a future reader "fixing" the map
     * back to the type that looks right.
     */
    public function testMapTypeDatetimeToTextBecausePostgresRejectsATemporalGeneratedColumn(): void
    {
        $this->assertSame('TEXT', $this->manager->mapTypeToSql('datetime'));
    }

    /**
     * `datetime` lands on TEXT only because the temporal column is unbuildable,
     * not because the value is prose. An ISO-8601 timestamp has no case to fold,
     * so it must not pick up the LOWER()-wrapped companion index that genuine
     * text fields get.
     */
    public function testDatetimeGetsNoCaseInsensitiveIndexDespiteMappingToText(): void
    {
        $this->assertSame('TEXT', $this->manager->mapTypeToSql('datetime'));
        $this->assertNull(
            $this->manager->getCaseInsensitiveIndexSql('nodes', 'v_publish_date', 'publishDate', 'datetime'),
        );
        // The contrast that gives the assertion meaning: a real text field DOES.
        $this->assertNotNull(
            $this->manager->getCaseInsensitiveIndexSql('nodes', 'v_title', 'title', 'string'),
        );
    }

    /**
     * A total must be exact. `float` is a binary double and cannot hold 0.1,
     * so money gets its own fixed-point type -- the difference is silent
     * corruption of a summed column, not a formatting preference.
     */
    public function testMapTypeMoneyToFixedPointNotFloat(): void
    {
        $this->assertSame('NUMERIC(19,4)', $this->manager->mapTypeToSql('money'));
        $this->assertNotSame(
            $this->manager->mapTypeToSql('float'),
            $this->manager->mapTypeToSql('money'),
        );
    }

    /**
     * A NUMERIC column must NOT get the LOWER()-wrapped companion index -- that
     * index only makes sense for text, and PostgreSQL has no LOWER(numeric).
     */
    public function testMoneyGetsNoCaseInsensitiveIndex(): void
    {
        $this->assertNull(
            $this->manager->getCaseInsensitiveIndexSql('products', 'v_total', 'total', 'money'),
        );
    }

    /**
     * `matchesDeclaredType()` decides whether an existing generated column is
     * rebuilt, and the answer is PLATFORM knowledge -- which is why it lives
     * here rather than being worked out from DBAL's type tables at the call
     * site. Every DATA_TYPES member must recognise the name the ORM reports for
     * the column this platform actually declares for it.
     *
     * Getting this WRONG in the permissive direction is the expensive one: a
     * false "diverged" rebuilds a STORED generated column on every field save.
     */
    public function testEveryDeclaredTypeRecognisesItsOwnColumn(): void
    {
        foreach ([
            'int' => 'integer',
            'bool' => 'boolean',
            'float' => 'float',
            'money' => 'decimal',
            'string' => 'text',
            'json' => 'text',
        ] as $phpType => $introspected) {
            $this->assertTrue(
                $this->manager->matchesDeclaredType($introspected, $phpType),
                sprintf('%s must recognise a %s column as its own', $phpType, $introspected),
            );
        }
    }

    /**
     * The PostgreSQL-specific override. Its `datetime` maps to TEXT (the
     * temporal generated column is unbuildable -- see above), so a date field's
     * column introspects as `text`. The INHERITED answer expects `datetime` and
     * would call every date field diverged, rebuilding on every save -- and that
     * rebuild then fails, because TIMESTAMP is exactly what this platform
     * refuses. The two defects compound.
     */
    public function testDatetimeRecognisesATextColumnBecauseThatIsWhatItDeclares(): void
    {
        $this->assertTrue($this->manager->matchesDeclaredType('text', 'datetime'));
        $this->assertFalse($this->manager->matchesDeclaredType('datetime', 'datetime'));
    }

    /** The other direction: a genuinely wrong column must still be caught. */
    public function testAColumnOfTheWrongTypeIsNotMatched(): void
    {
        $this->assertFalse($this->manager->matchesDeclaredType('text', 'money'));
        $this->assertFalse($this->manager->matchesDeclaredType('float', 'money'));
        $this->assertFalse($this->manager->matchesDeclaredType('text', 'int'));
    }

    /**
     * The DDL comes from the platform, never a literal at the call site -- the
     * seam exists so a caller never learns which database it is talking to.
     */
    public function testDropColumnSqlNamesOnlyTheGeneratedColumn(): void
    {
        $sql = $this->manager->getDropColumnSql('coolms_dynamic_records', 'v_price');

        $this->assertSame('ALTER TABLE coolms_dynamic_records DROP COLUMN v_price', $sql);
        // `extras` is the source of truth the generated column derives from;
        // naming it here would drop the data rather than the projection.
        $this->assertStringNotContainsString('extras', $sql);
    }

    public function testMapTypeDefaultToText(): void
    {
        $this->assertSame('TEXT', $this->manager->mapTypeToSql('string'));
        $this->assertSame('TEXT', $this->manager->mapTypeToSql('unknown'));
    }

    protected function setUp(): void
    {
        $this->manager = new PostgreSQLPlatformSchemaManager();
    }
}
