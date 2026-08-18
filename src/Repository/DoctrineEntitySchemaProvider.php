<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Repository;

use CoolMS\Core\Attribute\ClassMeta;
use CoolMS\Core\Attribute\FieldMeta;
use CoolMS\Core\Model\AggregateRootInterface;
use CoolMS\Entity\Contract\FieldMetadataSourceInterface;
use CoolMS\Entity\Registry\EntityAliasRegistry;
use CoolMS\Entity\Repository\EntitySchemaProviderInterface;
use CoolMS\Entity\ValueObject\EntityClassMetadata;
use CoolMS\Entity\ValueObject\EntityFieldMetadata;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\Persistence\ManagerRegistry;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

/**
 * Doctrine ORM implementation of EntitySchemaProviderInterface.
 *
 * This is the ONLY class in the codebase that uses Doctrine ORM metadata
 * for entity introspection. All higher layers depend on the Domain interface.
 *
 * Field visibility rules (no hardcoded names or suffix checks):
 *   SHOW if:
 *     a) Has Doctrine columnName (real DB column) AND no #[FieldMeta(private:true)]
 *     b) Has #[FieldMeta] without private:true (opt-in for non-Doctrine fields)
 *     c) Reported as a runtime field for this entityAlias by the field-metadata port
 *     d) Reported by that port as module config only (not already seen)
 *   HIDE if:
 *     a) #[FieldMeta(private:true)] -- explicit opt-out
 *     b) No Doctrine columnName AND no #[FieldMeta] -- computed hook / virtual property
 */
final readonly class DoctrineEntitySchemaProvider implements EntitySchemaProviderInterface
{
    public function __construct(
        private ManagerRegistry $registry,
        private EntityAliasRegistry $aliases,
        private FieldMetadataSourceInterface $fieldMetadata,
    ) {
    }

    /**
     * @throws MappingException
     *
     * @return EntityClassMetadata[]
     */
    public function getAllEntities(): array
    {
        $em = $this->registry->getManager();
        $factory = $em->getMetadataFactory();
        $result = [];

        foreach ($factory->getAllMetadata() as $meta) {
            /** @var class-string $className */
            $className = $meta->getName();

            // Skip Doctrine internal proxy classes
            if (str_contains($className, 'Proxies\\__CG__')) {
                continue;
            }

            // Skip classes with no #[ClassMeta] and no #[FieldMeta] properties
            // (vendor entities, Doctrine internals, etc.)
            if (!$this->isVisibleEntity($className)) {
                continue;
            }

            $module = $this->extractModule($className);
            $shortName = new ReflectionClass($className)->getShortName();

            $isAggregate = is_subclass_of($className, AggregateRootInterface::class);
            $isEmbeddable = $meta->isEmbeddedClass ?? $meta->isEmbeddable ?? false;
            $isDynamic = $this->aliases->hasAlias($className);
            $alias = $isDynamic ? $this->aliases->getAlias($className) : null;

            /** @var ClassMetadata<object> $ormMeta */
            $ormMeta = $meta;
            // Use the dynamic alias when available; fall back to FQCN so that
            // FieldDefinition records keyed by FQCN are still matched.
            $entityAlias = $alias ?? $className;

            $result[] = new EntityClassMetadata(
                className: $className,
                module: $module,
                shortName: $shortName,
                isAggregate: $isAggregate,
                isEmbeddable: $isEmbeddable,
                isDynamic: $isDynamic,
                dynamicAlias: $alias,
                label: $this->readClassLabel($className),
                fields: $this->buildFields($ormMeta, $className, $entityAlias),
            );
        }

        // Sort by module, then shortName for consistent ordering
        usort(
            $result,
            static fn (EntityClassMetadata $a, EntityClassMetadata $b) => [$a->module, $a->shortName] <=> [$b->module, $b->shortName],
        );

        return $result;
    }

    private function readClassLabel(string $className): string
    {
        try {
            /** @var class-string $className */
            $ref = new ReflectionClass($className);
            $attrs = $ref->getAttributes(ClassMeta::class);
            if ([] !== $attrs) {
                return $attrs[0]->newInstance()->label;
            }
        } catch (ReflectionException) {
        }

        return '';
    }

    private function extractModule(string $className): string
    {
        // App\{Module}\Domain\... yields {Module}
        $parts = explode('\\', $className);

        return $parts[1] ?? 'Unknown';
    }

    /**
     * @param ClassMetadata<object> $meta
     * @param class-string          $className
     *
     * @throws MappingException
     *
     * @return EntityFieldMetadata[]
     */
    private function buildFields(ClassMetadata $meta, string $className, string $entityAlias): array
    {
        $fields = [];
        /** @var string[] $seenNames */
        $seenNames = [];

        // Runtime (record-defined) fields, keyed by name -- the key set doubles
        // as the 'db' vs 'file' overrideKind discriminator for entity-source fields.
        $runtimeFields = $this->fieldMetadata->getRuntimeFieldSummaries($entityAlias);

        // Compute registry data once; reused in loop 4 and Pass 2.
        $registryData = $this->fieldMetadata->getResolvedFieldMetadata($className, $entityAlias);

        // -- Pass 1, loop 1: Doctrine columns -------------------------------
        foreach ($meta->getFieldNames() as $fieldName) {
            $fieldAttr = $this->readFieldMetaAttr($className, $fieldName);

            // Explicit private -- never show
            if (true === $fieldAttr?->private) {
                continue;
            }

            $mapping = $meta->getFieldMapping($fieldName);
            $columnName = $mapping->columnName ?? null;

            // No DB column + no annotation = computed/virtual property -> hide
            if (null === $columnName && null === $fieldAttr) {
                continue;
            }

            $seenNames[] = $fieldName;
            $fields[] = new EntityFieldMetadata(
                name: $fieldName,
                type: $this->mapDoctrineType($mapping->type ?? 'string'),
                nullable: (bool) ($mapping->nullable ?? false),
                isIdentifier: in_array($fieldName, $meta->getIdentifierFieldNames(), true),
                isEmbedded: false,
                label: $fieldAttr?->label,
                source: 'entity',
                overrideKind: isset($runtimeFields[$fieldName])
                    ? 'db'
                    : ($this->fieldMetadata->hasFileOverride($entityAlias, $fieldName) ? 'file' : null),
                enumClass: $mapping->enumType ?? null,
            );
        }

        // -- Pass 1, loop 2: Embedded VOs (e.g. ContentSlug, NaviSlug) ------
        foreach ($meta->embeddedClasses as $fieldName => $embeddedDef) {
            $fieldAttr = $this->readFieldMetaAttr($className, $fieldName);

            if (true === $fieldAttr?->private) {
                continue;
            }

            $seenNames[] = $fieldName;
            $fields[] = new EntityFieldMetadata(
                name: $fieldName,
                type: 'text',
                nullable: false,
                isIdentifier: false,
                isEmbedded: true,
                label: $fieldAttr?->label,
                source: 'entity',
                overrideKind: isset($runtimeFields[$fieldName])
                    ? 'db'
                    : ($this->fieldMetadata->hasFileOverride($entityAlias, $fieldName) ? 'file' : null),
            );
        }

        // -- Pass 1, loop 3: Runtime fields (record-defined) ----------------
        foreach ($runtimeFields as $name => $summary) {
            if (in_array($name, $seenNames, true)) {
                continue;
            }

            $seenNames[] = $name;
            $fields[] = new EntityFieldMetadata(
                name: $name,
                type: $summary['type'],
                nullable: true,
                isIdentifier: false,
                isEmbedded: false,
                label: '' !== $summary['label'] ? $summary['label'] : null,
                source: 'runtime',
                overrideKind: 'runtime',
            );
        }

        // -- Pass 1, loop 4: Module-only fields (YAML/PHP config, not yet seen) --
        // Adds fields that exist only in the registry (no Doctrine column, no DB row).
        // Two sub-cases:
        //   a) Backed by a real PHP property (hooked or regular, with or without
        //      #[FieldMeta]): source='entity'; a YAML file (if any) is an override
        //      over the PHP definition -> overrideKind='file', otherwise null.
        //   b) Defined purely in YAML (no PHP property at all): source='module';
        //      the YAML IS the definition -> overrideKind='module'.
        //
        // NOTE: We intentionally do NOT use readFieldMetaAttr() here to detect PHP
        // backing. PHP 8.4/8.5 has a reflection bug where getAttributes() returns []
        // for hooked properties regardless of the IS_INSTANCEOF flag. Instead we use
        // property existence (ReflectionProperty constructor) which is unaffected.
        foreach ($registryData as $name => $reg) {
            if (in_array($name, $seenNames, true)) {
                continue;
            }

            if ($reg['private']) {
                continue;
            }

            $hasPhpProperty = $this->propertyExists($className, $name);
            $hasFileOverride = $this->fieldMetadata->hasFileOverride($entityAlias, $name);

            // HIDE rule (b): a PHP property with no #[FieldMeta] and no module config
            // file is a computed / virtual property (e.g. $roles, $primaryGroupId).
            // These must not appear as editable schema fields.
            if ($hasPhpProperty && !$hasFileOverride && !$reg['hasMeta']) {
                continue;
            }

            $overrideKind = match (true) {
                $hasPhpProperty && $hasFileOverride => 'file',   // YAML overrides PHP property
                $hasPhpProperty => null,      // pure PHP property, no file override
                $hasFileOverride => 'module',  // YAML IS the definition
                default => null,
            };

            $seenNames[] = $name;
            $fields[] = new EntityFieldMetadata(
                name: $name,
                type: 'text',
                nullable: true,
                isIdentifier: false,
                isEmbedded: false,
                label: '' !== $reg['label'] ? $reg['label'] : null,
                source: $hasPhpProperty ? 'entity' : 'module',
                overrideKind: $overrideKind,
            );
        }

        // -- Pass 2: Apply registry overrides to every field ----------------
        // Loop 4 only adds registry-only fields (those not already in $seenNames).
        // This pass applies label / formType / sortOrder overrides from the registry
        // to ALL fields, including native Doctrine columns added in loops 1-2.
        $fields = array_map(
            function (EntityFieldMetadata $f) use ($registryData): EntityFieldMetadata {
                $reg = $registryData[$f->name] ?? null;
                if (null === $reg) {
                    return $f;
                }

                return new EntityFieldMetadata(
                    name: $f->name,
                    type: $f->type,
                    nullable: $f->nullable,
                    isIdentifier: $f->isIdentifier,
                    isEmbedded: $f->isEmbedded,
                    label: ('' !== $reg['label']) ? $reg['label'] : $f->label,
                    formType: $reg['formType'] ?? $f->formType,
                    showInForm: $f->showInForm,
                    sortOrder: $reg['sortOrder'] ?? $f->sortOrder,
                    source: $f->source,
                    overrideKind: $f->overrideKind,
                    enumClass: $f->enumClass,
                );
            },
            $fields,
        );

        // -- Sort by sortOrder ascending (nulls last) -----------------------
        usort(
            $fields,
            static fn (EntityFieldMetadata $a, EntityFieldMetadata $b): int => ($a->sortOrder ?? PHP_INT_MAX) <=> ($b->sortOrder ?? PHP_INT_MAX),
        );

        return $fields;
    }

    /**
     * Reads the #[FieldMeta] attribute directly from a property via Reflection.
     * Returns null when the property has no #[FieldMeta] annotation or does not exist.
     *
     * @param class-string $className
     */
    private function readFieldMetaAttr(string $className, string $fieldName): ?FieldMeta
    {
        try {
            $prop = new ReflectionProperty($className, $fieldName);

            // Standard attribute read.
            $attrs = $prop->getAttributes(FieldMeta::class);
            if ([] !== $attrs) {
                return $attrs[0]->newInstance();
            }

            // PHP 8.4 property hooks fallback: hooked properties may return []
            // for class-filtered getAttributes() -- IS_INSTANCEOF uses a different
            // code path that works correctly.
            $attrs = $prop->getAttributes(FieldMeta::class, ReflectionAttribute::IS_INSTANCEOF);
            if ([] !== $attrs) {
                return $attrs[0]->newInstance();
            }
        } catch (ReflectionException) {
        }

        return null;
    }

    /**
     * Returns true when $fieldName exists as a real PHP property on $className
     * (including hooked properties, which ReflectionProperty handles correctly
     * even when getAttributes() is broken for them in PHP 8.4/8.5).
     *
     * @param class-string $className
     */
    private function propertyExists(string $className, string $fieldName): bool
    {
        try {
            new ReflectionProperty($className, $fieldName);

            return true;
        } catch (ReflectionException) {
            return false;
        }
    }

    /**
     * Return true when $className carries at least one #[ClassMeta] attribute
     * or has at least one property annotated with #[FieldMeta] where private !== true.
     *
     * Properties from shared traits (IdentifierProviderTrait, ExtrasProviderTrait,
     * etc.) carry #[FieldMeta(private: true)] and must NOT trigger visibility on
     * their own -- only a class-level #[ClassMeta] or a non-private #[FieldMeta]
     * counts as an explicit opt-in.
     *
     * @param class-string $className
     */
    private function isVisibleEntity(string $className): bool
    {
        try {
            $ref = new ReflectionClass($className);
            if ([] !== $ref->getAttributes(ClassMeta::class)) {
                return true;
            }
            foreach ($ref->getProperties() as $prop) {
                $attrs = $prop->getAttributes(FieldMeta::class);
                if ([] === $attrs) {
                    continue;
                }
                /** @var FieldMeta $fieldMeta */
                $fieldMeta = $attrs[0]->newInstance();
                if (!$fieldMeta->private) {
                    return true; // at least one visible FieldMeta found
                }
            }
        } catch (ReflectionException) {
        }

        return false;
    }

    private function mapDoctrineType(string $doctrineType): string
    {
        return match ($doctrineType) {
            'integer', 'smallint', 'bigint' => 'int',
            'float', 'decimal' => 'float',
            'boolean' => 'bool',
            'datetime', 'datetime_immutable',
            'date', 'date_immutable',
            'datetimetz', 'datetimetz_immutable' => 'datetime',
            default => 'string',
        };
    }
}
