<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Cache;

use CoolMS\Entity\ExtrasProviderInterface;
use CoolMS\Entity\Registry\EntityAliasRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Cache\InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

readonly class ExtrasSchemaCacheInvalidator
{
    public function __construct(
        private TagAwareAdapterInterface $cache,
        private ManagerRegistry $managerRegistry,
        private EntityAliasRegistry $aliasRegistry,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function invalidate(ExtrasProviderInterface $entity): void
    {
        $this->invalidateByClassName($entity::class);
    }

    /**
     * Invalidate all metadata and schema caches for an ExtrasProvider entity class name.
     *
     * Prefer this over invalidate() when you only have a class name string (e.g.
     * from a FieldDefinition listener), to avoid constructing a throw-away entity
     * instance just to satisfy the type signature.
     *
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function invalidateByClassName(string $className): void
    {
        // DynamicRecord and other Runtime-mode entities are not registered in the
        // alias registry -- they have no schema cache to invalidate. Skip silently.
        // Only Extension-mode entities (PHP class + registered alias) need cache busting.
        if (!$this->aliasRegistry->hasAlias($className)) {
            return;
        }

        // Get the EntityManager for this class via ManagerRegistry -- never inject
        // EntityManagerInterface directly into constructors.
        /** @var class-string $className */
        $em = $this->managerRegistry->getManagerForClass($className);
        if (null === $em) {
            return; // The manager does not manage this class
        }
        assert($em instanceof EntityManagerInterface);

        // Reset Doctrine metadata runtime cache.
        // $loadedMetadata is declared private in AbstractClassMetadataFactory (doctrine/persistence),
        // not in DoctrineBundle's ClassMetadataFactory, so we must walk up the hierarchy to find
        // the declaring class before accessing it via reflection.
        $factory = $em->getMetadataFactory();
        $rc = new ReflectionClass($factory);
        while (false !== $rc && !$rc->hasProperty('loadedMetadata')) {
            $rc = $rc->getParentClass();
        }
        if (false !== $rc) {
            $property = $rc->getProperty('loadedMetadata');
            $loadedMetadata = $property->getValue($factory);
            if (isset($loadedMetadata[$className])) {
                unset($loadedMetadata[$className]);
                $property->setValue($factory, $loadedMetadata);
            }
        }

        // Clear system cache (PSR-6)
        $cacheDriver = $em->getConfiguration()->getMetadataCache();
        if ($cacheDriver) {
            $cacheKey = str_replace('\\', '_', $className) . '_METADATA';
            $cacheDriver->deleteItem($cacheKey);
        }

        // Reset Symfony validator cache
        $this->cache->invalidateTags(['dynamic_schema_' . $this->aliasRegistry->getAlias($className)]);

        // Reset API Platform cache -- PSR-6 keys cannot contain backslashes, so sanitize the FQCN.
        $safeClassName = str_replace('\\', '_', $className);
        $this->cache->deleteItem('api_platform.metadata.property.metadata_factory.' . $safeClassName);
    }
}
