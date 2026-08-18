<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Rql;

use CoolMS\Entity\VirtualField\VirtualFieldRegistryInterface;
use CoolMS\Rql\FilterNode;
use CoolMS\Rql\RqlQuery;
use Doctrine\ORM\QueryBuilder;

/**
 * Phase X-2.5b -- splits virtual-field filters off an RqlQuery,
 * applies them to the QueryBuilder via `VirtualFieldFilterApplier`,
 * and returns a new RqlQuery with those filters removed.
 *
 * Sibling-service pattern (per Sub-phase A): callers invoke this
 * once before handing the trimmed RqlQuery to `DoctrineRqlVisitor::applyFilters`.
 * The visitor and the applier write disjoint parameter-name
 * namespaces (`rql_*` vs `vfield_*`), so the two passes never
 * collide.
 *
 * Scope notes:
 *  - Only top-level `FilterNode`s are inspected. Virtual fields
 *    nested inside an `OrNode` are passed through unchanged --
 *    Sub-phase B does not support OR-grouped virtual filters.
 *  - The preprocessor mutates the QueryBuilder by appending
 *    `andWhere` clauses; calling it twice on the same QueryBuilder
 *    with the same RqlQuery applies the WHERE clauses twice.
 *    Callers driving separate base/count builders must call it
 *    once per builder.
 */
final readonly class RqlVirtualFieldPreprocessor
{
    public function __construct(
        private VirtualFieldRegistryInterface $registry,
        private VirtualFieldFilterApplier $applier,
    ) {
    }

    /**
     * Strips virtual-field filters from `$query`, applies them to
     * `$qb`, and returns the trimmed query (same sort/page/limit;
     * non-virtual filters retained in original order).
     */
    public function preprocess(QueryBuilder $qb, string $entityAlias, RqlQuery $query): RqlQuery
    {
        $remaining = [];
        $paramIndex = 0;
        $matched = false;

        foreach ($query->filters as $node) {
            if (!$node instanceof FilterNode) {
                $remaining[] = $node;
                continue;
            }
            $descriptor = $this->registry->findByName($entityAlias, $node->field);
            if (null === $descriptor) {
                $remaining[] = $node;
                continue;
            }
            $this->applier->apply($descriptor, $node, $qb, $paramIndex);
            ++$paramIndex;
            $matched = true;
        }

        if (!$matched) {
            return $query;
        }

        return new RqlQuery(
            filters: $remaining,
            sort: $query->sort,
            page: $query->page,
            limit: $query->limit,
        );
    }
}
