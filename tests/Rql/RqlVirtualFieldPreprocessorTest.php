<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Tests\Rql;

use CoolMS\Entity\Doctrine\Rql\RqlVirtualFieldPreprocessor;
use CoolMS\Entity\Doctrine\Rql\VirtualFieldFilterApplier;
use CoolMS\Entity\Filter\VirtualFieldDescriptor;
use CoolMS\Entity\VirtualField\VirtualFieldRegistryInterface;
use CoolMS\Rql\FilterNode;
use CoolMS\Rql\FilterOp;
use CoolMS\Rql\OrNode;
use CoolMS\Rql\RqlQuery;
use CoolMS\Rql\SortDirection;
use CoolMS\Rql\SortNode;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Phase X-2.5b -- preprocessor splits virtual filters off, applies
 * them via the applier, and hands the trimmed RqlQuery back so the
 * caller can pass it to `DoctrineRqlVisitor::applyFilters` next.
 */
final class RqlVirtualFieldPreprocessorTest extends TestCase
{
    public function testVirtualFilterStrippedAndAppliedToQueryBuilder(): void
    {
        $descriptor = new VirtualFieldDescriptor(
            name: 'daysSinceLastLogin',
            label: 'Days since last login',
            filterType: 'int',
            sqlExpression: 'DATE_DIFF(CURRENT_DATE(), u.lastLoginAt)',
            allowedOps: ['gt'],
        );
        $registry = $this->createStub(VirtualFieldRegistryInterface::class);
        $registry->method('findByName')->willReturnCallback(
            static fn (string $alias, string $name): ?VirtualFieldDescriptor => 'daysSinceLastLogin' === $name ? $descriptor : null,
        );

        $qb = $this->createMock(QueryBuilder::class);
        $qb->expects(self::once())
            ->method('andWhere')
            ->with(self::stringContains('DATE_DIFF(CURRENT_DATE(), u.lastLoginAt) >'))
            ->willReturnSelf();
        $qb->expects(self::once())
            ->method('setParameter')
            ->willReturnSelf();

        $preprocessor = new RqlVirtualFieldPreprocessor($registry, new VirtualFieldFilterApplier());

        $real = new FilterNode('username', FilterOp::Eq, 'alice');
        $virtual = new FilterNode('daysSinceLastLogin', FilterOp::Gt, 30);
        $query = new RqlQuery(filters: [$real, $virtual], page: 2, limit: 50);

        $trimmed = $preprocessor->preprocess($qb, 'user', $query);

        self::assertSame([$real], $trimmed->filters, 'Virtual filter must be stripped; real filter preserved in order.');
        self::assertSame(2, $trimmed->page);
        self::assertSame(50, $trimmed->limit);
    }

    public function testQueryReturnedUnchangedWhenNoVirtualFiltersPresent(): void
    {
        $registry = $this->createStub(VirtualFieldRegistryInterface::class);
        $registry->method('findByName')->willReturn(null);

        $qb = $this->createStub(QueryBuilder::class);
        $preprocessor = new RqlVirtualFieldPreprocessor($registry, new VirtualFieldFilterApplier());

        $query = new RqlQuery(
            filters: [new FilterNode('username', FilterOp::Eq, 'alice')],
            sort: [new SortNode('username', SortDirection::Asc)],
        );

        $trimmed = $preprocessor->preprocess($qb, 'user', $query);

        // Same-instance optimization -- no virtual filters means no
        // reallocation. Callers that compare by identity must see the
        // original.
        self::assertSame($query, $trimmed);
    }

    public function testOrNodesPassThroughUnchanged(): void
    {
        // Sub-phase B does not support virtual fields nested inside
        // OR groups; the preprocessor leaves OrNodes alone.
        $registry = $this->createStub(VirtualFieldRegistryInterface::class);
        $registry->method('findByName')->willReturn(null);

        $qb = $this->createStub(QueryBuilder::class);
        $preprocessor = new RqlVirtualFieldPreprocessor($registry, new VirtualFieldFilterApplier());

        $orNode = new OrNode([
            new FilterNode('username', FilterOp::Eq, 'a'),
            new FilterNode('username', FilterOp::Eq, 'b'),
        ]);
        $query = new RqlQuery(filters: [$orNode]);

        $trimmed = $preprocessor->preprocess($qb, 'user', $query);

        self::assertSame($query, $trimmed);
    }

    public function testMultipleVirtualFiltersGetDistinctParameterNames(): void
    {
        $first = new VirtualFieldDescriptor(
            name: 'daysSinceLastLogin',
            label: 'Days since last login',
            filterType: 'int',
            sqlExpression: 'DATE_DIFF(CURRENT_DATE(), u.lastLoginAt)',
            allowedOps: ['gt'],
        );
        $second = new VirtualFieldDescriptor(
            name: 'totalScore',
            label: 'Total score',
            filterType: 'int',
            sqlExpression: 'COALESCE(u.scoreA + u.scoreB, 0)',
            allowedOps: ['lt'],
        );
        $registry = $this->createStub(VirtualFieldRegistryInterface::class);
        $registry->method('findByName')->willReturnCallback(
            static fn (string $alias, string $name): ?VirtualFieldDescriptor => match ($name) {
                'daysSinceLastLogin' => $first,
                'totalScore' => $second,
                default => null,
            },
        );

        $capturedParams = [];
        $qb = $this->createMock(QueryBuilder::class);
        $qb->expects(self::exactly(2))->method('andWhere')->willReturnSelf();
        $qb->expects(self::exactly(2))
            ->method('setParameter')
            ->willReturnCallback(static function (string $name, mixed $value) use (&$capturedParams, $qb): QueryBuilder {
                $capturedParams[] = $name;

                return $qb;
            });

        $preprocessor = new RqlVirtualFieldPreprocessor($registry, new VirtualFieldFilterApplier());

        $query = new RqlQuery(filters: [
            new FilterNode('daysSinceLastLogin', FilterOp::Gt, 30),
            new FilterNode('totalScore', FilterOp::Lt, 100),
        ]);

        $preprocessor->preprocess($qb, 'user', $query);

        self::assertCount(2, $capturedParams);
        self::assertNotSame($capturedParams[0], $capturedParams[1], 'Distinct virtual filters must produce distinct parameter names.');
    }
}
