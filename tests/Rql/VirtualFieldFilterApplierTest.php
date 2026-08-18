<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Tests\Rql;

use CoolMS\Entity\Doctrine\Rql\VirtualFieldFilterApplier;
use CoolMS\Entity\Filter\VirtualFieldDescriptor;
use CoolMS\Rql\FilterNode;
use CoolMS\Rql\FilterOp;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Phase X-2.5b -- virtual field applier renders SQL expression
 * fragments (X strategy) and dispatches to translator callbacks
 * (Y strategy), honouring the descriptor's operator whitelist.
 */
final class VirtualFieldFilterApplierTest extends TestCase
{
    public function testSqlExpressionStrategyRendersEqWhere(): void
    {
        $descriptor = new VirtualFieldDescriptor(
            name: 'daysSinceCreated',
            label: 'Days since created',
            filterType: 'int',
            sqlExpression: 'DATE_DIFF(CURRENT_DATE(), e.createdAt)',
            allowedOps: ['eq', 'gt', 'lt'],
        );
        $qb = $this->createMock(QueryBuilder::class);
        $qb->expects(self::once())
            ->method('andWhere')
            ->with(self::matchesRegularExpression('/^DATE_DIFF\\(CURRENT_DATE\\(\\), e\\.createdAt\\) = :vfield_daysSinceCreated_\\d+$/'))
            ->willReturnSelf();
        $qb->expects(self::once())
            ->method('setParameter')
            ->with(self::stringStartsWith('vfield_daysSinceCreated_'), 30)
            ->willReturnSelf();

        new VirtualFieldFilterApplier()->apply(
            $descriptor,
            new FilterNode('daysSinceCreated', FilterOp::Eq, 30),
            $qb,
            7,
        );
    }

    public function testSqlExpressionStrategyRendersContainsWithLikePattern(): void
    {
        $descriptor = new VirtualFieldDescriptor(
            name: 'fullName',
            label: 'Full name',
            filterType: 'string',
            sqlExpression: "CONCAT(u.firstName, ' ', u.lastName)",
            allowedOps: ['cn'],
        );
        $qb = $this->createMock(QueryBuilder::class);
        $qb->expects(self::once())
            ->method('andWhere')
            ->with(self::stringContains("LOWER(CONCAT(u.firstName, ' ', u.lastName)) LIKE :"))
            ->willReturnSelf();
        $qb->expects(self::once())
            ->method('setParameter')
            ->with(self::anything(), '%alice%')
            ->willReturnSelf();

        new VirtualFieldFilterApplier()->apply(
            $descriptor,
            new FilterNode('fullName', FilterOp::Cn, 'Alice'),
            $qb,
            0,
        );
    }

    public function testSqlExpressionStrategyRendersInWithArrayValue(): void
    {
        $descriptor = new VirtualFieldDescriptor(
            name: 'tier',
            label: 'Tier',
            filterType: 'enum',
            sqlExpression: 'CASE WHEN u.score > 100 THEN \'gold\' ELSE \'silver\' END',
            allowedOps: ['in'],
        );
        $qb = $this->createMock(QueryBuilder::class);
        $qb->expects(self::once())
            ->method('andWhere')
            ->with(self::stringContains(' IN (:vfield_tier_'))
            ->willReturnSelf();
        $qb->expects(self::once())
            ->method('setParameter')
            ->with(self::anything(), ['gold', 'silver'])
            ->willReturnSelf();

        new VirtualFieldFilterApplier()->apply(
            $descriptor,
            new FilterNode('tier', FilterOp::In, ['gold', 'silver']),
            $qb,
            0,
        );
    }

    public function testTranslatorStrategyInvokesCallbackWithCorrectArgs(): void
    {
        $captured = [];
        $descriptor = new VirtualFieldDescriptor(
            name: 'computed',
            label: 'Computed',
            filterType: 'string',
            translator: static function (FilterOp $op, mixed $value, QueryBuilder $qb, string $paramName) use (&$captured): void {
                $captured = ['op' => $op, 'value' => $value, 'paramName' => $paramName];
            },
            allowedOps: ['eq'],
        );
        $qb = $this->createStub(QueryBuilder::class);

        new VirtualFieldFilterApplier()->apply(
            $descriptor,
            new FilterNode('computed', FilterOp::Eq, 'hello'),
            $qb,
            3,
        );

        self::assertSame(FilterOp::Eq, $captured['op']);
        self::assertSame('hello', $captured['value']);
        self::assertStringStartsWith('vfield_computed_', $captured['paramName']);
    }

    public function testOperatorOutsideWhitelistThrows(): void
    {
        $descriptor = new VirtualFieldDescriptor(
            name: 'daysSinceCreated',
            label: 'Days since created',
            filterType: 'int',
            sqlExpression: 'DATE_DIFF(CURRENT_DATE(), e.createdAt)',
            allowedOps: ['eq'],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("RQL operator 'gt' is not allowed on virtual field 'daysSinceCreated'");

        new VirtualFieldFilterApplier()->apply(
            $descriptor,
            new FilterNode('daysSinceCreated', FilterOp::Gt, 7),
            $this->createStub(QueryBuilder::class),
            0,
        );
    }

    public function testEmptyAllowedOpsAllowsAnyOperator(): void
    {
        $descriptor = new VirtualFieldDescriptor(
            name: 'open',
            label: 'Open',
            filterType: 'string',
            sqlExpression: 'e.name',
            allowedOps: [],
        );
        $qb = $this->createMock(QueryBuilder::class);
        $qb->expects(self::once())->method('andWhere')->willReturnSelf();
        $qb->expects(self::once())->method('setParameter')->willReturnSelf();

        new VirtualFieldFilterApplier()->apply(
            $descriptor,
            new FilterNode('open', FilterOp::Ne, 'x'),
            $qb,
            0,
        );
    }

    public function testNullStrategySkipsParameterBinding(): void
    {
        $descriptor = new VirtualFieldDescriptor(
            name: 'maybe',
            label: 'Maybe',
            filterType: 'int',
            sqlExpression: 'e.score',
            allowedOps: ['null'],
        );
        $qb = $this->createMock(QueryBuilder::class);
        $qb->expects(self::once())
            ->method('andWhere')
            ->with('e.score IS NULL')
            ->willReturnSelf();
        $qb->expects(self::never())->method('setParameter');

        new VirtualFieldFilterApplier()->apply(
            $descriptor,
            new FilterNode('maybe', FilterOp::Null, null),
            $qb,
            0,
        );
    }
}
