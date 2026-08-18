<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Tests\Mapping;

use CoolMS\Entity\Doctrine\Mapping\ExtrasFieldMappingDriver;
use CoolMS\Entity\Registry\EntityAliasRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExtrasFieldMappingDriverTest extends TestCase
{
    #[Test]
    public function mapsCamelCaseFieldToSnakeCaseColumn(): void
    {
        $driver = $this->makeDriver([
            ['name' => 'defaultOutputFormat', 'type' => 'text'],
        ]);

        /** @var ClassMetadata<object> $meta */
        $meta = new ClassMetadata(FakeAliasedEntity::class);

        $driver->loadMetadataForClass(FakeAliasedEntity::class, $meta);

        $mapping = $meta->getFieldMapping('defaultOutputFormat');
        self::assertSame(
            'v_default_output_format',
            $mapping->columnName ?? null,
            'UnderscoreNamingStrategy must transform camelCase to snake_case with v_ prefix preserved literally',
        );
        self::assertSame('defaultOutputFormat', $mapping->fieldName);
    }

    #[Test]
    public function singleWordFieldKeepsOriginalForm(): void
    {
        $driver = $this->makeDriver([
            ['name' => 'title', 'type' => 'text'],
        ]);

        /** @var ClassMetadata<object> $meta */
        $meta = new ClassMetadata(FakeAliasedEntity::class);

        $driver->loadMetadataForClass(FakeAliasedEntity::class, $meta);

        $mapping = $meta->getFieldMapping('title');
        self::assertSame('v_title', $mapping->columnName ?? null);
    }

    #[Test]
    public function alreadySnakeCaseFieldPassesThroughUnchanged(): void
    {
        // Runtime FieldDefinitions can be created with snake_case names directly.
        // UnderscoreNamingStrategy leaves them alone (no uppercase to transform).
        $driver = $this->makeDriver([
            ['name' => 'internal_notes', 'type' => 'text'],
        ]);

        /** @var ClassMetadata<object> $meta */
        $meta = new ClassMetadata(FakeAliasedEntity::class);

        $driver->loadMetadataForClass(FakeAliasedEntity::class, $meta);

        $mapping = $meta->getFieldMapping('internal_notes');
        self::assertSame('v_internal_notes', $mapping->columnName ?? null);
    }

    /**
     * @param list<array{name: string, type: string}> $fieldRows
     */
    private function makeDriver(array $fieldRows): ExtrasFieldMappingDriver
    {
        $schemaManager = $this->createStub(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);

        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->method('fetchAllAssociative')->willReturn($fieldRows);

        $registry = new EntityAliasRegistry([FakeAliasedEntity::class => 'fake_alias']);

        return new ExtrasFieldMappingDriver(
            delegate: $this->createStub(MappingDriver::class),
            connection: $connection,
            aliasRegistry: $registry,
            namingStrategy: new UnderscoreNamingStrategy(),
        );
    }
}

/**
 * @internal test-only fixture; not implementing ExtrasProviderInterface so the
 * mapping driver proceeds to the virtual-column emission path rather than
 * short-circuiting at the `is_subclass_of(ExtrasProviderInterface)` guard
 */
final class FakeAliasedEntity
{
}
