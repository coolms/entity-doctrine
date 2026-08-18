<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Mapping;

use CoolMS\Core\Mapping\Column;
use CoolMS\Core\Mapping\GeneratedValue;
use CoolMS\Core\Mapping\Id;
use Doctrine\ORM\Mapping\ClassMetadata as OrmClassMetadata;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;

/**
 * Translates the Domain's persistence-neutral mapping attributes into Doctrine
 * metadata.
 *
 * The reusable traits in Domain/Traits carry {@see Column} / {@see Id} /
 * {@see GeneratedValue} instead of Doctrine's own attributes, so the Domain
 * layer names no persistence library. Nothing reads those attributes until this
 * driver does, which is what keeps the dependency out.
 *
 * The alternative was declaring each trait's columns in every entity's own
 * mapping file. That is 79 entities repeating the same id, name and timestamp
 * blocks, and it turns "add a column to the trait" into a 79-file change that
 * nothing verifies. Here the trait stays the single source of truth.
 *
 * SCOPE: scalar columns and the identifier. Deliberately not associations,
 * embeddables or inheritance -- the traits use none of those, and the moment
 * this handles them it stops being a translation and becomes a second mapping
 * layer to keep in step with Doctrine's.
 */
readonly class TraitMappingDriver implements MappingDriver
{
    /**
     * Logical generator name -- the Doctrine class that implements it. This map
     * is the whole reason the Domain attribute carries a name rather than a
     * class: a Cycle adapter ships its own table, and neither has to know the
     * other's classes.
     *
     * @var array<string, class-string>
     */
    private const array GENERATORS = [
        GeneratedValue::UUID_V7 => UuidGenerator::class,
    ];

    public function __construct(
        private MappingDriver $delegate,
    ) {
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    public function loadMetadataForClass(string $className, ClassMetadata $metadata): void
    {
        $this->delegate->loadMetadataForClass($className, $metadata);

        if (!$metadata instanceof OrmClassMetadata) {
            return;
        }

        foreach ($this->traitPropertiesOf($className) as $property) {
            $this->apply($property, $metadata);
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

    /**
     * @param OrmClassMetadata<object> $metadata
     */
    private function apply(ReflectionProperty $property, OrmClassMetadata $metadata): void
    {
        $column = $property->getAttributes(Column::class)[0] ?? null;
        if (null === $column) {
            return;
        }

        $name = $property->getName();

        // The entity wins. A class that declares the field itself -- or a
        // parent that already contributed it -- is not overwritten, so adopting
        // the neutral attributes can never change an existing mapping.
        if (isset($metadata->fieldMappings[$name])) {
            return;
        }

        /** @var Column $c */
        $c = $column->newInstance();
        $isId = [] !== $property->getAttributes(Id::class);

        $mapping = [
            'fieldName' => $name,
            'type' => $c->type,
            'nullable' => $c->nullable,
            'unique' => $c->unique,
        ];
        if (null !== $c->name) {
            $mapping['columnName'] = $c->name;
        }
        if (null !== $c->length) {
            $mapping['length'] = $c->length;
        }
        if ([] !== $c->options) {
            $mapping['options'] = $c->options;
        }
        if (null !== $c->enumType) {
            $mapping['enumType'] = $c->enumType;
        }
        if ($isId) {
            $mapping['id'] = true;
        }

        $metadata->mapField($mapping);

        if (!$isId) {
            return;
        }

        $generated = $property->getAttributes(GeneratedValue::class)[0] ?? null;
        if (null === $generated) {
            return;
        }

        /** @var GeneratedValue $g */
        $g = $generated->newInstance();

        // Doctrine's constants are ints; the neutral attribute carries the name
        // so the Domain does not import them.
        $type = match ($g->strategy) {
            GeneratedValue::CUSTOM => OrmClassMetadata::GENERATOR_TYPE_CUSTOM,
            GeneratedValue::NONE => OrmClassMetadata::GENERATOR_TYPE_NONE,
            default => OrmClassMetadata::GENERATOR_TYPE_AUTO,
        };
        $metadata->setIdGeneratorType($type);

        if (OrmClassMetadata::GENERATOR_TYPE_CUSTOM !== $type || null === $g->generator) {
            return;
        }

        // Refused, not ignored. Falling through would leave the id with no
        // generator at all, and Doctrine reports that as a missing value on the
        // first insert rather than as the mapping error it is.
        if (!isset(self::GENERATORS[$g->generator])) {
            throw new InvalidArgumentException(sprintf('Unknown generator "%s" on %s::$%s. Add it to %s::GENERATORS.', $g->generator, $property->getDeclaringClass()->getName(), $name, self::class));
        }

        $metadata->setCustomGeneratorDefinition(['class' => self::GENERATORS[$g->generator]]);
    }

    /**
     * Every property declared by a trait the class composes, however deep.
     *
     * `class_uses()` returns only the traits used DIRECTLY by that one class:
     * not traits used by those traits, and not traits on parent classes. Both
     * gaps are silent -- the column simply never gets mapped -- so the walk is
     * explicit here.
     *
     * @return list<ReflectionProperty>
     */
    private function traitPropertiesOf(string $className): array
    {
        if (!class_exists($className)) {
            return [];
        }

        $traits = [];
        for ($class = $className; false !== $class; $class = get_parent_class($class)) {
            $this->collectTraits($class, $traits);
        }

        $properties = [];
        foreach (array_keys($traits) as $trait) {
            foreach (new ReflectionClass($trait)->getProperties() as $property) {
                $properties[] = $property;
            }
        }

        return $properties;
    }

    /**
     * @param array<class-string, true> $seen
     */
    private function collectTraits(string $class, array &$seen): void
    {
        foreach (class_uses($class) ?: [] as $trait) {
            if (isset($seen[$trait])) {
                continue;
            }
            $seen[$trait] = true;
            $this->collectTraits($trait, $seen);
        }
    }
}
