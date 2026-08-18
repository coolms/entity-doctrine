<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine;

use CoolMS\Core\Attribute\ClassMeta;
use CoolMS\Entity\Registry\EntityAliasRegistryInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use ReflectionAttribute;
use ReflectionClass;

/**
 * `EntityAliasRegistryInterface` backed by `#[ClassMeta(alias:…)]`
 * on Doctrine-registered entity classes.
 *
 * Discovery walks `getMetadataFactory()->getAllMetadata()` (the
 * same source `DoctrineEntitySchemaProvider` uses for Domain
 * Explorer) and reads each class's `ClassMeta` attribute via
 * reflection. The map is lazily built on first access and cached
 * for the lifetime of the service -- registry usage is per-template
 * upload, not a hot path, so a single up-front scan when the first
 * upload arrives is cheaper than wiring a CompilerPass that has to
 * traverse `getAllMetadata()` at build time too.
 *
 * Pure ClassMeta source -- no runtime-type awareness. Composite
 * with `EntityAliasRegistry` (see
 * `CompositeEntityAliasRegistry`) layers the dynamic slugs on top
 * when both are present.
 *
 * Three indices are cached side by side:
 *   - `singularAliasToFqcn` -- backwards-compatible `all()` source.
 *   - `aliasToFqcn` (union)  -- powers `resolve` / `has` and accepts
 *                               both singular and collection forms.
 *   - `aliasToMeta`          -- powers `findByAlias`, keyed by both
 *                               singular and collection forms.
 *   - `collectionAliases`    -- set of collection-form aliases for
 *                               `isCollectionAlias`.
 */
final class ClassMetaEntityAliasRegistry implements EntityAliasRegistryInterface
{
    /**
     * @var array<string, class-string>|null
     */
    private ?array $singularAliasToFqcn = null;

    /**
     * @var array<string, class-string>|null
     */
    private ?array $aliasToFqcn = null;

    /**
     * @var array<string, ClassMeta>|null
     */
    private ?array $aliasToMeta = null;

    /**
     * @var array<string, true>|null
     */
    private ?array $collectionAliases = null;

    public function __construct(
        private readonly ManagerRegistry $registry,
    ) {
    }

    public function resolve(string $alias): ?string
    {
        $this->ensureBuilt();

        return ($this->aliasToFqcn ?? [])[$alias] ?? null;
    }

    public function aliasOf(string $entityType): ?string
    {
        $this->ensureBuilt();
        $alias = array_search($entityType, $this->singularAliasToFqcn ?? [], true);

        return false === $alias ? null : $alias;
    }

    public function has(string $alias): bool
    {
        $this->ensureBuilt();

        return isset(($this->aliasToFqcn ?? [])[$alias]);
    }

    public function all(): array
    {
        $this->ensureBuilt();

        return $this->singularAliasToFqcn ?? [];
    }

    public function findByAlias(string $alias): ?ClassMeta
    {
        $this->ensureBuilt();

        return ($this->aliasToMeta ?? [])[$alias] ?? null;
    }

    public function isCollectionAlias(string $alias): bool
    {
        $this->ensureBuilt();

        return isset(($this->collectionAliases ?? [])[$alias]);
    }

    private function ensureBuilt(): void
    {
        if (null !== $this->singularAliasToFqcn) {
            return;
        }
        $factory = $this->registry->getManager()->getMetadataFactory();
        $singular = [];
        $union = [];
        $metaByAlias = [];
        $collectionSet = [];

        foreach ($factory->getAllMetadata() as $doctrineMeta) {
            /** @var class-string $class */
            $class = $doctrineMeta->getName();
            // Skip Doctrine internals / proxies -- same guard as
            // DoctrineEntitySchemaProvider uses.
            if (str_contains($class, 'Proxies\\__CG__')) {
                continue;
            }
            $meta = $this->readMeta($class);
            if (null === $meta) {
                continue;
            }
            $singularAlias = is_string($meta->alias) && '' !== $meta->alias ? $meta->alias : null;
            $collectionAlias = is_string($meta->aliasCollection) && '' !== $meta->aliasCollection
                ? $meta->aliasCollection
                : null;
            if (null === $singularAlias && null === $collectionAlias) {
                continue;
            }
            if (null !== $singularAlias) {
                if (isset($union[$singularAlias])) {
                    throw new LogicException(sprintf('Duplicate entity alias "%s": both %s and %s declare it via #[ClassMeta].', $singularAlias, $union[$singularAlias], $class));
                }
                $singular[$singularAlias] = $class;
                $union[$singularAlias] = $class;
                $metaByAlias[$singularAlias] = $meta;
            }
            if (null !== $collectionAlias) {
                if (isset($union[$collectionAlias])) {
                    throw new LogicException(sprintf('Duplicate entity alias "%s": collection alias on %s collides with an existing alias on %s.', $collectionAlias, $class, $union[$collectionAlias]));
                }
                $union[$collectionAlias] = $class;
                $metaByAlias[$collectionAlias] = $meta;
                $collectionSet[$collectionAlias] = true;
            }
        }

        $this->singularAliasToFqcn = $singular;
        $this->aliasToFqcn = $union;
        $this->aliasToMeta = $metaByAlias;
        $this->collectionAliases = $collectionSet;
    }

    /**
     * @param class-string $class
     */
    private function readMeta(string $class): ?ClassMeta
    {
        $attrs = new ReflectionClass($class)->getAttributes(
            ClassMeta::class,
            ReflectionAttribute::IS_INSTANCEOF,
        );
        if ([] === $attrs) {
            return null;
        }

        return $attrs[0]->newInstance();
    }
}
