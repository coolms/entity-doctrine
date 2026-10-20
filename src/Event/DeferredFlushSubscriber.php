<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Event;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Deferred Flush Subscriber: flushes every open entity manager at kernel.response, kernel.terminate and
 * console.terminate -- ALWAYS, whatever any mode says. Code that persists without flushing relies on it today: its
 * writes reach the database here, at the end of the request or command.
 *
 * It stays while an application can run without a unit-of-work owner (coolms/core-bundle, core.transaction.mode off):
 * there it is the only flush such code gets. With the owners on, the owner's commit flushes first and this finds
 * nothing left. It goes when the off mode does.
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
