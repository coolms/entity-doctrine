<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Tree;

use CoolMS\Core\Hierarchy\MaterializedPathNodeInterface;
use CoolMS\Core\Hierarchy\MaterializedPathOperatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use RuntimeException;

/**
 * Generic Doctrine implementation of MaterializedPathOperatorInterface.
 *
 * updatePath() is a pure PHP computation -- no DB queries.
 * moveSubtree() uses a DQL UPDATE with CONCAT/SUBSTRING for a safe,
 * cross-vendor prefix-only replacement.
 *
 * Safe prefix replacement rationale:
 *   The old REPLACE(path, :old, :new) approach is a global search-and-replace
 *   that corrupts rows where the old path appears mid-string:
 *     REPLACE('/cats-food', '/cats', '/new') -- '/new-food' -- BUG
 *   The correct approach cuts at a known byte position:
 *     CONCAT(:newPath, SUBSTRING(n.path, :prefixLength))
 *   where :prefixLength = strlen($oldPath) + 1 is computed in PHP.
 *
 * DQL functions used -- built-in, no custom registration, cross-vendor:
 *   CONCAT(a, b) -- MySQL: CONCAT(), PostgreSQL/SQLite: ||
 *   SUBSTRING(str, start) -- MySQL/PostgreSQL: SUBSTRING(), SQLite: SUBSTR()
 *
 * The EntityManager is resolved at call-time via ManagerRegistry::getManagerForClass(get_class($node)),
 * making this operator compatible with multi-EM setups.
 *
 * Constructor args:
 *  - $separator -- path separator character, defaults to '/'
 */
final readonly class DoctrineMaterializedPathOperator implements MaterializedPathOperatorInterface
{
    public function __construct(
        private ManagerRegistry $registry,
        private string $separator = '/',
    ) {
    }

    public function updatePath(MaterializedPathNodeInterface $node): void
    {
        $node->path = null === $node->parent
            ? $this->separator . $node->pathSegment
            : $node->parent->path . $this->separator . $node->pathSegment;
    }

    public function moveSubtree(MaterializedPathNodeInterface $node, string $oldPath): void
    {
        /** @var class-string $entityClass */
        $entityClass = get_class($node);
        // Compute prefix length in PHP -- avoids DQL LENGTH() complexity.
        $prefixLength = mb_strlen($oldPath, '8bit') + 1;
        $this->em($entityClass)
            ->createQueryBuilder()
            ->update($entityClass, 'n')
            ->set('n.path', 'CONCAT(:newPath, SUBSTRING(n.path, :prefixLength))')
            ->where('n.path LIKE :prefix')
            ->setParameter('newPath', $node->path)
            ->setParameter('prefixLength', $prefixLength)
            ->setParameter('prefix', $oldPath . '%')
            ->getQuery()
            ->execute();
    }

    /**
     * Resolve the EntityManager for the given entity class.
     * ManagerRegistry caches the manager internally, so this is a cheap call.
     *
     * @param class-string $entityClass
     *
     * @throws RuntimeException if no EM is mapped for $entityClass
     */
    private function em(string $entityClass): EntityManagerInterface
    {
        $em = $this->registry->getManagerForClass($entityClass);
        if (!$em instanceof EntityManagerInterface) {
            throw new RuntimeException(sprintf('No EntityManager found for class "%s". Ensure it is a Doctrine entity.', $entityClass));
        }

        return $em;
    }
}
