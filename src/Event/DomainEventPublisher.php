<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Event;

use CoolMS\Core\Event\ActorStamp;
use CoolMS\Core\Event\DomainEventInterface;
use CoolMS\Core\Event\PriorityStamp;
use CoolMS\Core\Event\RecordedEventsProviderInterface;
use CoolMS\Core\Event\TraceIdStamp;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Dispatches Domain Events recorded by aggregate roots after every
 * Doctrine flush.
 *
 * Flow:
 *   Entity::someAction() -- recordEvent(new SomethingHappened(...))
 *   EntityManager::flush()
 *     -- Doctrine postFlush
 *       -- DomainEventPublisher::postFlush()
 *         -- collectPendingEvents() per drain iteration
 *            (atomic flush per aggregate; clears immediately)
 *         -- usort by priority (High first)
 *         -- $bus->dispatch(Envelope + ActorStamp + TraceIdStamp + PriorityStamp)
 *
 * **Queue-and-drain (resolves the May 17 re-entrant-flush gap)**:
 * the publisher keeps looping until no aggregate in the current UoW
 * identity-map snapshot has new events. Handlers invoked via the
 * sync messenger transport may load new aggregates (or record on
 * existing ones) and then call `save()` -- the nested flush re-enters
 * `postFlush()` but the re-entrancy guard short-circuits it. The
 * drained-and-then-re-snapshot loop catches the events those nested
 * flushes left behind on the next iteration; nothing is lost.
 *
 * Eventually consistent: events recorded by a handler are dispatched
 * after the handler returns, not transactionally with it.
 *
 * Safety: a bounded iteration counter (`MAX_DRAIN_ITERATIONS`) bails
 * out of a runaway handler that records new events on every cycle.
 * Hitting the cap logs an error and the loop returns -- the
 * pathological aggregate stays in memory with un-dispatched events
 * so the next legitimate flush still has a chance to drain them.
 *
 * Registered automatically via `#[AsDoctrineListener]` -- no DI
 * configuration needed.
 */
#[AsDoctrineListener(event: 'postFlush')]
final class DomainEventPublisher
{
    /**
     * Upper bound on drain iterations per outer `postFlush` call.
     * Real-world workloads complete in 1--3 iterations; anything
     * above ~10 is almost certainly a handler bug. The cap is
     * generous (100) to avoid false positives during edge-case
     * legitimate fan-out while still bounding worst-case work.
     */
    private const int MAX_DRAIN_ITERATIONS = 100;

    private bool $isDispatching = false;

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->isDispatching) {
            // Re-entrant call from a handler that called flush().
            // Returning here is the right move: events recorded
            // during this nested flush stay on their aggregates,
            // and the outer drain loop picks them up on its next
            // iteration.
            return;
        }

        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $userId = $this->security->getUser()?->getUserIdentifier() ?? 'anonymous';

        $this->isDispatching = true;
        try {
            $iterations = 0;
            while ($iterations < self::MAX_DRAIN_ITERATIONS) {
                $events = $this->collectPendingEvents($uow);
                if ([] === $events) {
                    return;
                }
                $this->dispatchAll($events, $userId);
                ++$iterations;
            }

            // Reaching the cap means a handler kept recording new
            // events on every cycle. Log loudly so the runaway is
            // visible; any events still on aggregates will be
            // picked up by the next legitimate flush.
            $this->logger->error(
                'DomainEventPublisher: drain loop exceeded max iterations -- a handler is likely recording events indefinitely.',
                ['maxIterations' => self::MAX_DRAIN_ITERATIONS],
            );
        } finally {
            $this->isDispatching = false;
        }
    }

    /**
     * Scan the current UoW identity map and pull every recorded
     * event from every aggregate that has one. Returns a flat
     * list ordered by priority (High first).
     *
     * Called per drain iteration -- the snapshot is re-fetched
     * every time so newly-loaded aggregates are visible to the
     * next pass.
     *
     * @return list<DomainEventInterface>
     */
    private function collectPendingEvents(UnitOfWork $uow): array
    {
        /** @var list<DomainEventInterface> $events */
        $events = [];

        foreach ($uow->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                if (!$entity instanceof RecordedEventsProviderInterface) {
                    continue;
                }
                $pulled = $entity->flushRecordedEvents();
                if ([] === $pulled) {
                    continue;
                }
                foreach ($pulled as $event) {
                    $events[] = $event;
                }
            }
        }

        if ([] === $events) {
            return [];
        }

        usort(
            $events,
            static fn (DomainEventInterface $a, DomainEventInterface $b): int => $a->priority->sortWeight() <=> $b->priority->sortWeight(),
        );

        return $events;
    }

    /**
     * @param list<DomainEventInterface> $events
     */
    private function dispatchAll(array $events, string $userId): void
    {
        foreach ($events as $event) {
            $this->bus->dispatch(new Envelope($event, [
                new ActorStamp($userId),
                new TraceIdStamp(Uuid::v7()->toRfc4122()),
                new PriorityStamp($event->priority),
            ]));
        }
    }
}
