<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Rql;

use Closure;
use CoolMS\Entity\Filter\VirtualFieldDescriptor;
use CoolMS\Rql\FilterNode;
use CoolMS\Rql\FilterOp;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;

/**
 * Phase X-2.5b -- applies a single RQL filter node to a Doctrine
 * QueryBuilder when the targeted field is a virtual field.
 *
 * Two strategies, selected by the descriptor:
 *
 *  - X (SQL expression): renders `<expression> <op> :param` and binds
 *    the param. The expression is treated as a DQL/SQL fragment by
 *    Doctrine; the descriptor's author owns its correctness.
 *  - Y (translator callback): invokes the Closure with the signature
 *    `function(FilterOp $op, mixed $value, QueryBuilder $qb, string $paramName): void`.
 *    The callback owns the entire QueryBuilder mutation; the applier
 *    only provides the unique parameter name to keep collisions away
 *    from the surrounding `DoctrineRqlVisitor` parameter namespace.
 *
 * The operator is validated against the descriptor's `allowedOps`
 * before either strategy runs.
 */
final readonly class VirtualFieldFilterApplier
{
    public function apply(
        VirtualFieldDescriptor $descriptor,
        FilterNode $node,
        QueryBuilder $qb,
        int $paramIndex,
    ): void {
        $opCode = $node->op->value;
        if ([] !== $descriptor->allowedOps && !in_array($opCode, $descriptor->allowedOps, true)) {
            throw new InvalidArgumentException(sprintf("RQL operator '%s' is not allowed on virtual field '%s' (allowed: %s).", $opCode, $descriptor->name, implode(', ', $descriptor->allowedOps)));
        }

        $paramName = 'vfield_' . preg_replace('/[^a-z0-9]/i', '_', $descriptor->name) . '_' . $paramIndex;

        if ($descriptor->hasTranslator()) {
            /** @var Closure $translator */
            $translator = $descriptor->translator;
            $translator($node->op, $node->value, $qb, $paramName);

            return;
        }

        $this->applySqlExpression($descriptor, $node, $qb, $paramName);
    }

    private function applySqlExpression(
        VirtualFieldDescriptor $descriptor,
        FilterNode $node,
        QueryBuilder $qb,
        string $paramName,
    ): void {
        /** @var string $expression Non-null per XOR validation on VirtualFieldDescriptor when hasTranslator() is false. */
        $expression = $descriptor->sqlExpression;

        match ($node->op) {
            FilterOp::Eq => $qb->andWhere("$expression = :$paramName")->setParameter($paramName, $node->value),
            FilterOp::Ne => $qb->andWhere("$expression != :$paramName")->setParameter($paramName, $node->value),
            FilterOp::Gt => $qb->andWhere("$expression > :$paramName")->setParameter($paramName, $node->value),
            FilterOp::Lt => $qb->andWhere("$expression < :$paramName")->setParameter($paramName, $node->value),
            FilterOp::Ge => $qb->andWhere("$expression >= :$paramName")->setParameter($paramName, $node->value),
            FilterOp::Le => $qb->andWhere("$expression <= :$paramName")->setParameter($paramName, $node->value),
            FilterOp::Cn => $qb->andWhere("LOWER($expression) LIKE :$paramName")->setParameter($paramName, '%' . mb_strtolower((string) $node->value) . '%'),
            FilterOp::Bw => $qb->andWhere("LOWER($expression) LIKE :$paramName")->setParameter($paramName, mb_strtolower((string) $node->value) . '%'),
            FilterOp::Ew => $qb->andWhere("LOWER($expression) LIKE :$paramName")->setParameter($paramName, '%' . mb_strtolower((string) $node->value)),
            FilterOp::Null => $qb->andWhere("$expression IS NULL"),
            FilterOp::Nn => $qb->andWhere("$expression IS NOT NULL"),
            FilterOp::In => $qb->andWhere("$expression IN (:$paramName)")->setParameter($paramName, (array) $node->value),
            FilterOp::Ni => $qb->andWhere("$expression NOT IN (:$paramName)")->setParameter($paramName, (array) $node->value),
        };
    }
}
