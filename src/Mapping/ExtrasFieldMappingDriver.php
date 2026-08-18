<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Mapping;

use CoolMS\Entity\ExtrasProviderInterface;
use CoolMS\Entity\Registry\EntityAliasRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Mapping\NamingStrategy;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;

readonly class ExtrasFieldMappingDriver implements MappingDriver
{
    public function __construct(
        private MappingDriver $delegate,
        private Connection $connection,
        private EntityAliasRegistry $aliasRegistry,
        private NamingStrategy $namingStrategy,
    ) {
    }

    /**
     * @param \Doctrine\ORM\Mapping\ClassMetadata<object> $metadata
     *
     * @throws MappingException
     */
    public function loadMetadataForClass(string $className, ClassMetadata $metadata): void
    {
        // Load standard metadata from the chain driver (Attributes)
        $this->delegate->loadMetadataForClass($className, $metadata);

        // Only process aliased ExtrasProvider entities (not the MappedSuperclass itself)
        if (!$this->aliasRegistry->hasAlias($className) || $metadata->isMappedSuperclass) {
            return;
        }

        // Entities that use ExtrasProviderTrait store all dynamic field values in a single
        // JSON `$extras` column -- there are no discrete `v_{name}` generated DB columns.
        // Adding ORM field mappings for them would cause SchemaValidator to look for PHP
        // properties that don't exist and Doctrine to SELECT columns that aren't there.
        // For these entities, FieldDefinition records serve API Platform / serializer
        // metadata only (via EntitySchemaLookup / ExtrasPropertyMetadataFactory) -- they must
        // NOT be reflected into Doctrine ORM column mappings.
        if (is_subclass_of($className, ExtrasProviderInterface::class)) {
            return;
        }

        $entityAlias = $this->aliasRegistry->getAlias($className);

        // Use DBAL directly -- avoids circular dependency with ORM (which needs metadata to boot).
        // Guard against fresh installs where the migration has not yet run.
        try {
            if (!$this->connection->createSchemaManager()->tablesExist(['coolms_dynamic_field_definitions'])) {
                return;
            }

            $rows = $this->connection->fetchAllAssociative(
                <<<SQL
                    SELECT name, type FROM coolms_dynamic_field_definitions WHERE entity_alias = ?
                    SQL,
                [$entityAlias],
            );
        } catch (Exception) {
            // Any connectivity or schema issue: skip silently rather than breaking boot.
            return;
        }

        foreach ($rows as $row) {
            // Map the virtual generated column so Doctrine can SELECT it.
            // notInsertable / notUpdatable because the DB populates these GENERATED ALWAYS columns.
            // Column name follows the project NamingStrategy (camelCase -> snake_case under
            // UnderscoreNamingStrategy) so the physical name matches what DynamicSchemaManager
            // emits via DDL. The `v_` prefix is a literal marker, not a property name, and is
            // applied verbatim.
            $metadata->mapField([
                'fieldName' => $row['name'],
                'type' => $row['type'],
                'columnName' => 'v_' . $this->namingStrategy->propertyToColumnName($row['name'], $className),
                'nullable' => true,
                'notInsertable' => true,
                'notUpdatable' => true,
                'options' => [
                    'dynamic' => true,
                    'source_property' => 'extras',
                ],
            ]);
        }
    }

    public function getAllClassNames(): array
    {
        return $this->delegate->getAllClassNames();
    }

    public function isTransient($className): bool
    {
        return $this->delegate->isTransient($className);
    }
}
