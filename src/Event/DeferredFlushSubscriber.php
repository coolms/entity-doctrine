<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Event;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Deferred Flush Subscriber.
 *
 * When `coolms.persistence.transaction_mode` is enabled, ObjectManager::save() and delete()
 * skip the immediate flush(). This subscriber ensures all pending UoW changes are flushed
 * at the end of the request/command lifecycle:
 *
 *   - HTTP request -- kernel.response (before sending the response)
 *   - HTTP shutdown -- kernel.terminate (after the response is sent)
 *   - CLI command -- console.terminate
 *
 * Only one flush per lifecycle phase is performed. If the entity manager is already closed
 * (e.g., due to an exception), it is skipped safely.
 */
final readonly class DeferredFlushSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['flush', -1024],
            KernelEvents::TERMINATE => ['flush', -1024],
            ConsoleEvents::TERMINATE => ['flush', -1024],
        ];
    }

    public function flush(): void
    {
        foreach ($this->managerRegistry->getManagers() as $manager) {
            if ($manager instanceof EntityManagerInterface && $manager->isOpen()) {
                $manager->flush();
            }
        }
    }
}
