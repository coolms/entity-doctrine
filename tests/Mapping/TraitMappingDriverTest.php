<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Tests\Mapping;

use CoolMS\Core\Mapping\Column;
use CoolMS\Core\Mapping\GeneratedValue;
use CoolMS\Core\Mapping\Id;
use CoolMS\Entity\Doctrine\Mapping\TraitMappingDriver;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;

final class TraitMappingDriverTest extends TestCase
{
    #[Test]
    public function mapsColumnsDeclaredByATrait(): void
    {
        $meta = $this->load(FakeTraitEntity::class);

        $mapping = $meta->getFieldMapping('title');
        self::assertSame('title', $mapping->fieldName);
        self::assertSame('string', $mapping->type);
        self::assertTrue($mapping->nullable ?? false);
    }

    #[Test]
    public function honoursAnExplicitColumnName(): void
    {
        $meta = $this->load(FakeTraitEntity::class);

        self::assertSame('sort_order', $meta->getFieldMapping('position')->columnName ?? null);
    }

    /**
     * The point of the logical name: the Domain attribute says 'uuid_v7' and the
     * adapter -- not the entity -- decides which class implements it.
     */
    #[Test]
    public function resolvesTheLogicalGeneratorNameToTheDoctrineClass(): void
    {
        $meta = $this->load(FakeTraitEntity::class);

        self::assertSame(ClassMetadata::GENERATOR_TYPE_CUSTOM, $meta->generatorType);
        self::assertSame(UuidGenerator::class, $meta->customGeneratorDefinition['class'] ?? null);
    }

    #[Test]
    public function refusesAnUnknownGeneratorName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown generator "nope"/');

        $this->load(FakeBadGeneratorEntity::class);
    }

    /**
     * Adopting the neutral attributes must never change a mapping that already
     * exists -- an entity (or its XML) that declares the field itself wins.
     */
    #[Test]
    public function doesNotOverwriteAFieldTheEntityAlreadyMapped(): void
    {
        $meta = new ClassMetadata(FakeTraitEntity::class);
        $meta->mapField(['fieldName' => 'title', 'type' => 'text', 'columnName' => 'own_title']);

        $this->driver()->loadMetadataForClass(FakeTraitEntity::class, $meta);

        $mapping = $meta->getFieldMapping('title');
        self::assertSame('text', $mapping->type, 'the entity-declared mapping must survive');
        self::assertSame('own_title', $mapping->columnName ?? null);
    }

    /**
     * class_uses() sees only the traits used directly by one class. Both gaps it
     * leaves -- traits used by traits, and traits on a parent -- are silent, so
     * they get their own case.
     */
    #[Test]
    public function walksTraitsOfTraitsAndParentClasses(): void
    {
        $meta = $this->load(FakeChildEntity::class);

        self::assertSame('note', $meta->getFieldMapping('note')->fieldName, 'trait-of-a-trait on a parent class');
    }

    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private function load(string $class): ClassMetadata
    {
        /** @var ClassMetadata<object> $meta */
        $meta = new ClassMetadata($class);
        $this->driver()->loadMetadataForClass($class, $meta);

        return $meta;
    }

    private function driver(): TraitMappingDriver
    {
        return new TraitMappingDriver($this->createStub(MappingDriver::class));
    }
}

/** @internal test-only fixture */
trait FakeIdentityTrait
{
    public function __construct(
        #[Id]
        #[Column(type: 'uuid', unique: true)]
        #[GeneratedValue(strategy: GeneratedValue::CUSTOM, generator: GeneratedValue::UUID_V7)]
        public Uuid $id,
    ) {
    }
}

/** @internal test-only fixture */
trait FakeInnerTrait
{
    #[Column(type: 'string')]
    public string $note = '';
}

/** @internal test-only fixture; exercises the trait-of-a-trait walk */
trait FakeOuterTrait
{
    use FakeInnerTrait;
}

/** @internal test-only fixture */
trait FakeColumnsTrait
{
    #[Column(type: 'string', nullable: true)]
    public ?string $title = null;

    #[Column(name: 'sort_order', type: 'integer')]
    public int $position = 0;
}

/** @internal test-only fixture */
final class FakeTraitEntity
{
    use FakeColumnsTrait;
    use FakeIdentityTrait;
}

/** @internal test-only fixture */
trait FakeBadGeneratorTrait
{
    #[Id]
    #[Column(type: 'uuid')]
    #[GeneratedValue(strategy: GeneratedValue::CUSTOM, generator: 'nope')]
    public ?Uuid $id = null;
}

/** @internal test-only fixture */
final class FakeBadGeneratorEntity
{
    use FakeBadGeneratorTrait;
}

/** @internal test-only fixture */
class FakeParentEntity
{
    use FakeOuterTrait;
}

/** @internal test-only fixture; the trait it needs is on the PARENT */
final class FakeChildEntity extends FakeParentEntity
{
}
