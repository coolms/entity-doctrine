<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Listener;

use CoolMS\Entity\Doctrine\Cache\ExtrasSchemaCacheInvalidator;
use CoolMS\Entity\ExtrasProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * @deprecated since 2026-09-24, with no replacement. It ran on every persist,
 *             update and remove of an ExtrasProviderInterface entity to call an
 *             invalidator whose operations have no reader. It carries no
 *             #[AsDoctrineListener] any more, so it runs only where an application
 *             registers it by hand. Removed at the next generation boundary.
 */
final readonly class ExtrasSchemaCacheInvalidatorListener
{
    public function __construct(
        private ExtrasSchemaCacheInvalidator $cacheInvalidator,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->invalidateCache($args);
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->invalidateCache($args);
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->invalidateCache($args);
    }

    /**
     * @param LifecycleEventArgs<EntityManagerInterface> $args
     *
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function invalidateCache(LifecycleEventArgs $args): void
    {
        @trigger_error(
            'CoolMS\\Entity\\Doctrine\\Listener\\ExtrasSchemaCacheInvalidatorListener is deprecated since 2026-09-24: '
            . 'none of its four operations has a reader, and its listener no longer runs; it is removed at the next generation boundary.',
            E_USER_DEPRECATED,
        );

        $object = $args->getObject();
        if ($object instanceof ExtrasProviderInterface) {
            $this->cacheInvalidator->invalidate($object);
        }
    }
}
