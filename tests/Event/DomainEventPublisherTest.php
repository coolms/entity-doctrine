<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Tests\Event;

use CoolMS\Core\Event\AbstractDomainEvent;
use CoolMS\Core\Event\EventPriority;
use CoolMS\Core\Event\RecordedEventsProviderInterface;
use CoolMS\Core\Event\RecordedEventsTrait;
use CoolMS\Entity\Doctrine\Event\DomainEventPublisher;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Re-entrant-flush fix (May 17, 2026): the publisher must keep draining
 * until no aggregate has new events. The three core scenarios:
 *
 *   1. Nested-event drain -- a handler records on a freshly-loaded
 *      aggregate; that aggregate appears in the next snapshot and its
 *      event is dispatched on the next iteration.
 *   2. Re-entrant call is a clean no-op -- the inner postFlush
 *      triggered by a handler-side flush() returns immediately
 *      without losing or duplicating events.
 *   3. Bounded iteration -- a handler that records new events
 *      indefinitely is capped and logged, not allowed to livelock.
 *
 * The first scenario is the actual regression test for a
 * `Running -> Completed` publication gap: a handler that recorded a
 * further event during flush had that event silently swallowed. The
 * fourth test covers the simple-case baseline so nobody can revert to
 * the swallowing behaviour.
 */
final class DomainEventPublisherTest extends TestCase
{
    #[Test]
    public function simpleCaseDispatchesEveryRecordedEventOnce(): void
    {
        $agg = new FakeAggregate();
        $agg->recordSomething('one');
        $agg->recordSomething('two');

        $captured = [];
        $bus = $this->makeCapturingBus($captured);

        $publisher = $this->makePublisher($bus);
        $publisher->postFlush($this->makePostFlushArgs([FakeAggregate::class => [$agg]]));

        self::assertCount(2, $captured);
        self::assertSame(['one', 'two'], array_map(static function (object $e): string {
            assert($e instanceof FakeEvent);

            return $e->payload;
        }, $captured));
    }

    #[Test]
    public function nestedEventRecordedByHandlerDispatchesOnDrain(): void
    {
        // The flagship regression: aggregate A is in the identity map at
        // call time; aggregate B is loaded INSIDE the handler that
        // dispatches A's event. The drain loop must re-snapshot the
        // identity map and pick up B's recorded event on the next
        // iteration.
        $aggA = new FakeAggregate('A');
        $aggA->recordSomething('event-a');
        $aggB = new FakeAggregate('B');

        $identityMap = [FakeAggregate::class => ['A' => $aggA]];
        $uow = $this->makeUnitOfWork($identityMap);

        $captured = [];
        $bus = $this->makeCapturingBus($captured, function (object $message) use (&$identityMap, $aggB): void {
            if ($message instanceof FakeEvent && 'event-a' === $message->payload) {
                // Simulate the listener loading aggregate B and
                // recording on it. In production this happens via
                // `findById` + a method that calls `recordEvent()`.
                $aggB->recordSomething('event-b');
                $identityMap[FakeAggregate::class]['B'] = $aggB;
            }
        });

        $publisher = $this->makePublisher($bus);
        $publisher->postFlush($this->makePostFlushArgsFromUow($uow));

        $payloads = array_map(static function (object $e): string {
            assert($e instanceof FakeEvent);

            return $e->payload;
        }, $captured);
        self::assertSame(['event-a', 'event-b'], $payloads, 'Nested event must drain on the next iteration.');
    }

    #[Test]
    public function reEntrantCallReturnsImmediatelyWithoutDispatching(): void
    {
        // Mid-drain, a handler-side flush re-enters postFlush. The
        // re-entrancy guard short-circuits; the outer loop picks up
        // events the inner flush left on aggregates on its next
        // iteration.
        $aggA = new FakeAggregate('A');
        $aggA->recordSomething('event-a');
        $aggB = new FakeAggregate('B');

        $identityMap = [FakeAggregate::class => ['A' => $aggA]];
        $uow = $this->makeUnitOfWork($identityMap);
        $publisher = null;

        $captured = [];
        $bus = $this->makeCapturingBus($captured, function (object $message) use (&$identityMap, &$publisher, $aggB, $uow): void {
            if ($message instanceof FakeEvent && 'event-a' === $message->payload) {
                $aggB->recordSomething('event-b');
                $identityMap[FakeAggregate::class]['B'] = $aggB;
                // Simulate the handler flushing -- nested postFlush
                // call with $isDispatching === true must short-circuit.
                // PHPStan can`t track `$publisher` getting assigned
                // outside the closure scope after this lambda is built.
                /* @phpstan-ignore notIdentical.alwaysFalse */
                if (null !== $publisher) {
                    $publisher->postFlush($this->makePostFlushArgsFromUow($uow));
                }
            }
        });

        $publisher = $this->makePublisher($bus);
        $publisher->postFlush($this->makePostFlushArgsFromUow($uow));

        $payloads = array_map(static function (object $e): string {
            assert($e instanceof FakeEvent);

            return $e->payload;
        }, $captured);
        self::assertSame(['event-a', 'event-b'], $payloads, 'Re-entrant postFlush must not lose nested events.');
    }

    #[Test]
    public function highPriorityEventsDispatchBeforeNormal(): void
    {
        $agg = new FakeAggregate();
        $agg->recordSomething('normal-1', EventPriority::Normal);
        $agg->recordSomething('high-1', EventPriority::High);
        $agg->recordSomething('normal-2', EventPriority::Normal);

        $captured = [];
        $bus = $this->makeCapturingBus($captured);

        $publisher = $this->makePublisher($bus);
        $publisher->postFlush($this->makePostFlushArgs([FakeAggregate::class => [$agg]]));

        $payloads = array_map(static function (object $e): string {
            assert($e instanceof FakeEvent);

            return $e->payload;
        }, $captured);
        self::assertSame(['high-1', 'normal-1', 'normal-2'], $payloads);
    }

    #[Test]
    public function runawayHandlerHitsIterationCapAndLogsError(): void
    {
        // Pathological handler: every event triggers a new event on
        // a fresh aggregate. The drain bound must terminate this
        // and log error rather than livelock.
        $agg = new FakeAggregate();
        $agg->recordSomething('seed');

        $identityMap = [FakeAggregate::class => ['seed' => $agg]];
        $uow = $this->makeUnitOfWork($identityMap);
        $counter = 0;

        $captured = [];
        $bus = $this->makeCapturingBus($captured, function (object $message) use (&$identityMap, &$counter): void {
            $nextId = 'gen-' . ++$counter;
            $next = new FakeAggregate($nextId);
            $next->recordSomething('event-' . $counter);
            $identityMap[FakeAggregate::class][$nextId] = $next;
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('exceeded max iterations'));

        $publisher = $this->makePublisher($bus, $logger);
        $publisher->postFlush($this->makePostFlushArgsFromUow($uow));

        // The exact count is the cap; verify we didn't loop unbounded.
        self::assertLessThanOrEqual(101, $counter, 'Drain must terminate at the cap, not run forever.');
    }

    private function makePublisher(MessageBusInterface $bus, ?LoggerInterface $logger = null): DomainEventPublisher
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        return new DomainEventPublisher(
            bus: $bus,
            security: $security,
            logger: $logger ?? new NullLogger(),
        );
    }

    /**
     * @param array<class-string, array<int|string, object>> $identityMap
     */
    private function makePostFlushArgs(array $identityMap): PostFlushEventArgs
    {
        return $this->makePostFlushArgsFromUow($this->makeUnitOfWork($identityMap));
    }

    private function makePostFlushArgsFromUow(UnitOfWork $uow): PostFlushEventArgs
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        return new PostFlushEventArgs($em);
    }

    /**
     * @param array<class-string, array<int|string, object>> $identityMap passed by reference so test bodies can mutate it mid-drain
     */
    private function makeUnitOfWork(array &$identityMap): UnitOfWork
    {
        $uow = $this->createStub(UnitOfWork::class);
        // willReturnCallback re-reads the array on every call, so
        // mutations between drain iterations are visible.
        $uow->method('getIdentityMap')->willReturnCallback(static function () use (&$identityMap): array {
            return $identityMap;
        });

        return $uow;
    }

    /**
     * Bus stub that records every dispatched message into the
     * caller-supplied array (by reference) and optionally runs a
     * "handler" side-effect on each message -- the in-test stand-in
     * for the sync messenger transport's synchronous handler call.
     *
     * @param list<object>           $captured   passed by reference; populated in dispatch order
     * @param ?callable(object):void $onDispatch invoked with the unwrapped message just before the bus returns
     */
    private function makeCapturingBus(array &$captured, ?callable $onDispatch = null): MessageBusInterface
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $env) use (&$captured, $onDispatch): Envelope {
            $envelope = $env instanceof Envelope ? $env : new Envelope($env);
            $message = $envelope->getMessage();
            $captured[] = $message;
            if (null !== $onDispatch) {
                $onDispatch($message);
            }

            return $envelope;
        });

        return $bus;
    }
}

/**
 * Aggregate-fake that records events on demand. Implements the
 * project's `RecordedEventsProviderInterface` via the standard
 * `RecordedEventsTrait`, so the publisher treats it exactly like
 * a real aggregate.
 */
final class FakeAggregate implements RecordedEventsProviderInterface
{
    use RecordedEventsTrait;

    public function __construct(public readonly string $id = 'default')
    {
    }

    public function recordSomething(string $payload, EventPriority $priority = EventPriority::Normal): void
    {
        $this->recordEvent(new FakeEvent($this->id, $payload, $priority));
    }
}

/**
 * Test-only event with a controllable priority -- used to verify the
 * publisher's sort step (High first within a single drain iteration).
 */
final class FakeEvent extends AbstractDomainEvent
{
    public string $eventName {
        get => 'test.fake_event';
    }
    public EventPriority $priority {
        get => $this->priorityValue;
    }

    public function __construct(
        string $entityId,
        public readonly string $payload,
        private readonly EventPriority $priorityValue = EventPriority::Normal,
        DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {
        parent::__construct($entityId, $occurredAt);
    }
}
