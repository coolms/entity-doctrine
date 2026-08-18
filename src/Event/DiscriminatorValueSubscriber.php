<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Event;

use CoolMS\Entity\Doctrine\Attribute\DiscriminatorValue;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use ReflectionClass;

/**
 * Doctrine event listener that reads #[DiscriminatorValue] attributes and
 * registers each annotated class into its root entity's discriminator map.
 *
 * This decouples subclass bundles (e.g., SSO) from the parent entity bundle
 * (Authentication): neither needs to import the other to build the map.
 *
 * Registered via #[AsDoctrineListener] -- picked up by the App\ resource scan
 * with autoconfigure=true. No explicit DI registration is required.
 *
 * ## Strategy: eager scan on a root load
 *
 * Doctrine metadata is loaded lazily. If we only register a subclass when its
 * own metadata fires (the naive approach), then the root entity's discriminator
 * map stays incomplete until each subclass is individually touched. Any DQL
 * query built before that point gets "AND type IN ('user')" only, silently
 * excluding all subclass rows.
 *
 * Instead, when the ROOT entity's metadata fires, we immediately scan ALL driver
 * class names for subclasses carrying #[DiscriminatorValue] and register every
 * one of them into the root's map upfront. Subsequent per-subclass events are
 * then handled normally (updating the child's own metadata copy so that
 * validateRuntimeMetadata() passes).
 *
 * ## How Doctrine's metadata loading works (ORM 3.x)
 *
 * ClassMetadataFactory::doLoadMetadata() processes each class in this order:
 *   1. Copies the parent's discriminatorMap into the child's own metadata
 *   2. Skips addDefaultDiscriminatorMap() because $class->discriminatorMap is non-empty
 *      (we seed the root with #[ORM\DiscriminatorMap(['root_value' => Root::class])])
 *   3. Fires the loadClassMetadata event (this listener)
 *   4. Calls findAbstractEntityClassesNotListedInDiscriminatorMap()
 *   5. Calls validateRuntimeMetadata() -- checks that $class->name ∈ $class->discriminatorMap
 *
 * For child classes, validateRuntimeMetadata() checks the CHILD's own metadata copy
 * (set at step 1), not the root's live map. So when a child fires at step 3, we must
 * also update its own copy, or step 5 will throw "has to be part of the discriminator map".
 */
#[AsDoctrineListener(event: Events::loadClassMetadata)]
class DiscriminatorValueSubscriber
{
    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $classMetadata = $event->getClassMetadata();
        $className = $classMetadata->getName();
        $attributes = $this->getDiscriminatorValueAttribute($className);
        if (null === $attributes) {
            return;
        }
        $value = $attributes->value;
        // Always set the discriminator value on this class's own metadata.
        $classMetadata->discriminatorValue = $value;
        $rootName = $classMetadata->rootEntityName;
        if ($rootName === $className) {
            // This IS the root entity. Register its own value and then eagerly
            // scan all driver classes to find and pre-register every subclass
            // that carries #[DiscriminatorValue]. This ensures the full
            // discriminator map is built immediately -- before any DQL query
            // runs -- rather than piecemeal as each subclass is lazily loaded.
            $classMetadata->discriminatorMap[$value] = $className;
            $em = $event->getObjectManager();
            $driver = $em->getConfiguration()->getMetadataDriverImpl();
            if (null === $driver) {
                return;
            }
            foreach ($driver->getAllClassNames() as $candidateClass) {
                if ($candidateClass === $className) {
                    continue;
                }
                if (!is_subclass_of($candidateClass, $className)) {
                    continue;
                }
                $childAttr = $this->getDiscriminatorValueAttribute($candidateClass);
                if (null === $childAttr) {
                    continue;
                }
                $childValue = $childAttr->value;
                if (!isset($classMetadata->discriminatorMap[$childValue])) {
                    $classMetadata->discriminatorMap[$childValue] = $candidateClass;
                }
                if (!in_array($candidateClass, $classMetadata->subClasses, true)) {
                    $classMetadata->subClasses[] = $candidateClass;
                }
            }

            return;
        }

        // Child class: the root map was already populated during the root's
        // loadClassMetadata event above. We still need to update TWO things:
        //
        // 1. The root's authoritative map in case this child was somehow missed
        //    during the eager scan (defensive).
        // 2. The CHILD's own metadata copy (inherited from the root at step 1 of
        //    doLoadMetadata). validateRuntimeMetadata() checks this copy, not
        //    the root's live map.
        $em = $event->getObjectManager();
        /** @var ClassMetadata<object> $rootMetadata */
        $rootMetadata = $em->getClassMetadata($rootName);
        if (!isset($rootMetadata->discriminatorMap[$value])) {
            $rootMetadata->discriminatorMap[$value] = $className;
        }
        if (!in_array($className, $rootMetadata->subClasses, true)) {
            $rootMetadata->subClasses[] = $className;
        }
        // Update the child's own inherited copy so validateRuntimeMetadata() passes.
        foreach ($rootMetadata->discriminatorMap as $k => $v) {
            $classMetadata->discriminatorMap[$k] = $v;
        }
    }

    private function getDiscriminatorValueAttribute(string $className): ?DiscriminatorValue
    {
        /** @var class-string $className */
        $refClass = new ReflectionClass($className);
        $attributes = $refClass->getAttributes(DiscriminatorValue::class);

        return [] !== $attributes ? $attributes[0]->newInstance() : null;
    }
}
