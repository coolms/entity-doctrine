<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Listener;

use CoolMS\Entity\Doctrine\Cache\ExtrasSchemaCacheInvalidator;
use CoolMS\Entity\ExtrasProviderInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

#[AsDoctrineListener(event: Events::postPersist, priority: 100)]
#[AsDoctrineListener(event: Events::postUpdate, priority: 100)]
#[AsDoctrineListener(event: Events::postRemove, priority: 100)]
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
        $object = $args->getObject();
        if ($object instanceof ExtrasProviderInterface) {
            $this->cacheInvalidator->invalidate($object);
        }
    }
}
