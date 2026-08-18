<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Tree;

use CoolMS\Core\Hierarchy\NestedSetNodeInterface;
use CoolMS\Core\Hierarchy\NestedSetOperatorInterface;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\Persistence\ManagerRegistry;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Generic Doctrine ORM implementation of NestedSetOperatorInterface.
 *
 * All mutations use DQL QueryBuilder (no raw SQL) for full DB-agnosticism.
 * rebuildIndexes() uses PHP-side DFS traversal via the NestedSetNodeInterface
 * $children property hook -- avoids PostgreSQL-specific recursive CTEs.
 *
 * The EntityManager is resolved at call-time via ManagerRegistry::getManagerForClass(),
 * making this operator compatible with multi-EM setups (split read/write, per-tenant, etc.).
 *
 * Constructor args:
 *  - $entityClass -- the concrete Doctrine entity class (e.g., TaxonomyNode::class)
 *  - $treeFkExpr -- DQL expression that evaluates to the tree's UUID for a given
 *                    node alias 'n'. Use 'IDENTITY(n.tree)' for a ManyToOne FK, or
 *                    'n.treeId' for a plain UUID column.
 *
 * Each consuming module registers this service with the appropriate arguments:
 *
 *   $container->register(NestedSetOperatorInterface::class, DoctrineNestedSetOperator::class)
 *       ->setArgument('$entityClass', MyNode::class)
 *       ->setArgument('$treeFkExpr', 'IDENTITY(n.tree)');
 */
final class DoctrineNestedSetOperator implements NestedSetOperatorInterface
{
    private EntityManagerInterface $em {
        get {
            if (!isset($this->em)) {
                $em = $this->registry->getManagerForClass($this->entityClass);
                if (!$em instanceof EntityManagerInterface) {
                    throw new RuntimeException(sprintf('No EntityManager found for class "%s". Ensure it is a Doctrine entity.', $this->entityClass));
                }
                $this->em = $em;
            }

            return $this->em;
        }
    }

    /**
     * @param class-string $entityClass
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly string $entityClass,
        private readonly string $treeFkExpr,
    ) {
    }

    /**
     * @throws MappingException
     * @throws Exception
     */
    public function lockTree(Uuid $treeId): void
    {
        $platform = $this->em->getConnection()->getDatabasePlatform();
        if (!$platform instanceof Platforms\PostgreSQLPlatform
            && !$platform instanceof Platforms\MySQLPlatform
            && !$platform instanceof Platforms\OraclePlatform
        ) {
            return;
        }
        $metadata = $this->em->getClassMetadata($this->entityClass);
        $tableName = $metadata->getTableName();
        $treeMapping = $metadata->getAssociationMapping('tree');
        // Object-property access (not ArrayAccess): ORM 3 deprecates ArrayAccess
        // on AssociationMapping / JoinColumnMapping (removed in ORM 4).
        $treeColumn = $treeMapping->joinColumns[0]->name ?? 'tree_id';
        $sql = sprintf(
            <<<SQL
                SELECT 1 FROM %s WHERE %s = :treeId FOR UPDATE
                SQL,
            $tableName,
            $treeColumn,
        );
        $this->em->getConnection()->executeStatement($sql, [
            'treeId' => $treeId->toRfc4122(),
        ]);
    }

    public function getMaxRgt(Uuid $treeId): int
    {
        $result = $this->em->createQueryBuilder()
            ->select('MAX(n.rgt)')
            ->from($this->entityClass, 'n')
            ->where($this->treeFkExpr . ' = :treeId')
            ->setParameter('treeId', $treeId->toRfc4122())
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    public function shiftRight(Uuid $treeId, int $threshold, int $delta): void
    {
        // NOTE: $delta is inlined into the SET expression (not a named parameter) because
        // Doctrine's MultiTableUpdateExecutor (used for JOINED-inheritance entities) does not
        // forward SET-clause parameters to its auxiliary UPDATE statements, causing
        // "SQLSTATE[HY093]: Invalid parameter number: parameter was not defined".
        // $delta is typed int, so there is no SQL-injection risk.
        $this->em->createQueryBuilder()
            ->update($this->entityClass, 'n')
            ->set('n.rgt', sprintf('n.rgt + %d', $delta))
            ->where($this->treeFkExpr . ' = :treeId AND n.rgt >= :threshold')
            ->setParameter('treeId', $treeId->toRfc4122())
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }

    public function shiftLeft(Uuid $treeId, int $threshold, int $delta): void
    {
        // See shiftRight() for the rationale behind inlining $delta.
        $this->em->createQueryBuilder()
            ->update($this->entityClass, 'n')
            ->set('n.lft', sprintf('n.lft + %d', $delta))
            ->where($this->treeFkExpr . ' = :treeId AND n.lft > :threshold')
            ->setParameter('treeId', $treeId->toRfc4122())
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }

    public function deleteSubtree(Uuid $treeId, int $lft, int $rgt): void
    {
        $this->em->createQueryBuilder()
            ->delete($this->entityClass, 'n')
            ->where($this->treeFkExpr . ' = :treeId AND n.lft >= :lft AND n.rgt <= :rgt')
            ->setParameter('treeId', $treeId->toRfc4122())
            ->setParameter('lft', $lft)
            ->setParameter('rgt', $rgt)
            ->getQuery()
            ->execute();
    }

    public function shiftSubtree(Uuid $treeId, int $lft, int $rgt, int $delta): void
    {
        // See shiftRight() for the rationale behind inlining $delta.
        $this->em->createQueryBuilder()
            ->update($this->entityClass, 'n')
            ->set('n.rgt', sprintf('n.rgt + %d', $delta))
            ->set('n.lft', sprintf('n.lft + %d', $delta))
            ->where($this->treeFkExpr . ' = :treeId AND n.lft >= :lft AND n.rgt <= :rgt')
            ->setParameter('treeId', $treeId->toRfc4122())
            ->setParameter('lft', $lft)
            ->setParameter('rgt', $rgt)
            ->getQuery()
            ->execute();
    }

    public function updateSubtreeLevel(Uuid $treeId, int $lft, int $rgt, int $levelOffset): void
    {
        if (0 === $levelOffset) {
            return;
        }

        // See shiftRight() for the rationale behind inlining $levelOffset.
        $this->em->createQueryBuilder()
            ->update($this->entityClass, 'n')
            ->set('n.level', sprintf('n.level + %d', $levelOffset))
            ->where($this->treeFkExpr . ' = :treeId AND n.lft >= :lft AND n.rgt <= :rgt')
            ->setParameter('treeId', $treeId->toRfc4122())
            ->setParameter('lft', $lft)
            ->setParameter('rgt', $rgt)
            ->getQuery()
            ->execute();
    }

    public function rebuildIndexes(NestedSetNodeInterface $root): void
    {
        $counter = 0;
        $this->dfsAssign($root, 0, $counter);
        $this->em->flush();
    }

    public function transactional(callable $fn): void
    {
        $this->em->wrapInTransaction($fn);
    }

    private function dfsAssign(NestedSetNodeInterface $node, int $level, int &$counter): void
    {
        $node->lft = ++$counter;
        $node->level = $level;
        foreach ($node->children as $child) {
            $this->dfsAssign($child, $level + 1, $counter);
        }
        $node->rgt = ++$counter;
    }
}
