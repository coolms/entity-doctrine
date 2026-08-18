<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Tests;

use CoolMS\Core\Attribute\ClassMeta;
use CoolMS\Entity\Doctrine\ClassMetaEntityAliasRegistry;
use CoolMS\Entity\Doctrine\Tests\Fixture\TestInvoice;
use CoolMS\Entity\Doctrine\Tests\Fixture\TestOrder;
use CoolMS\Entity\Doctrine\Tests\Fixture\TestUnaliased;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ClassMeta-driven entity alias registry, with focus
 * on the Phase 2b additions: aliasCollection support, findByAlias
 * meta lookup, and isCollectionAlias discrimination.
 */
final class ClassMetaEntityAliasRegistryTest extends TestCase
{
    public function testRegistryResolvesBySingularAlias(): void
    {
        $registry = $this->makeRegistry([TestInvoice::class, TestOrder::class]);

        self::assertSame(TestInvoice::class, $registry->resolve('invoice'));
        self::assertSame(TestOrder::class, $registry->resolve('order'));
    }

    public function testRegistryResolvesByCollectionAlias(): void
    {
        $registry = $this->makeRegistry([TestInvoice::class]);

        self::assertSame(TestInvoice::class, $registry->resolve('invoices'));
    }

    public function testRegistryFindByAliasReturnsMetaForEitherForm(): void
    {
        $registry = $this->makeRegistry([TestInvoice::class]);

        $singular = $registry->findByAlias('invoice');
        $collection = $registry->findByAlias('invoices');

        self::assertInstanceOf(ClassMeta::class, $singular);
        self::assertInstanceOf(ClassMeta::class, $collection);
        self::assertSame('invoice', $singular->alias);
        self::assertSame('invoices', $singular->aliasCollection);
        // Same ClassMeta instance returned for either alias form.
        self::assertSame($singular, $collection);
    }

    public function testRegistryFindByAliasReturnsNullForUnknown(): void
    {
        $registry = $this->makeRegistry([TestInvoice::class]);

        self::assertNull($registry->findByAlias('nonexistent'));
    }

    public function testRegistryIsCollectionAliasDistinguishes(): void
    {
        $registry = $this->makeRegistry([TestInvoice::class, TestOrder::class]);

        self::assertTrue($registry->isCollectionAlias('invoices'));
        self::assertFalse($registry->isCollectionAlias('invoice'));
        self::assertFalse($registry->isCollectionAlias('order'));
        self::assertFalse($registry->isCollectionAlias('unknown'));
    }

    public function testRegistryHasRecognizesBothAliasForms(): void
    {
        $registry = $this->makeRegistry([TestInvoice::class]);

        self::assertTrue($registry->has('invoice'));
        self::assertTrue($registry->has('invoices'));
        self::assertFalse($registry->has('unknown'));
    }

    public function testRegistryAllReturnsSingularAliasesOnly(): void
    {
        $registry = $this->makeRegistry([TestInvoice::class, TestOrder::class]);

        $all = $registry->all();

        self::assertArrayHasKey('invoice', $all);
        self::assertArrayHasKey('order', $all);
        self::assertArrayNotHasKey('invoices', $all);
        self::assertSame(TestInvoice::class, $all['invoice']);
    }

    public function testRegistryAliasOfReturnsSingularAlias(): void
    {
        $registry = $this->makeRegistry([TestInvoice::class]);

        self::assertSame('invoice', $registry->aliasOf(TestInvoice::class));
        self::assertNull($registry->aliasOf(TestUnaliased::class));
    }

    public function testRegistrySkipsClassesWithoutAlias(): void
    {
        $registry = $this->makeRegistry([TestInvoice::class, TestUnaliased::class]);

        self::assertNull($registry->aliasOf(TestUnaliased::class));
        self::assertFalse($registry->has('unaliased'));
    }

    /**
     * @param list<class-string> $classes
     */
    private function makeRegistry(array $classes): ClassMetaEntityAliasRegistry
    {
        $metadatas = array_map(function (string $class): ClassMetadata {
            $meta = $this->createStub(ClassMetadata::class);
            $meta->method('getName')->willReturn($class);

            return $meta;
        }, $classes);

        $factory = $this->createStub(ClassMetadataFactory::class);
        $factory->method('getAllMetadata')->willReturn($metadatas);

        $manager = $this->createStub(ObjectManager::class);
        $manager->method('getMetadataFactory')->willReturn($factory);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);

        return new ClassMetaEntityAliasRegistry($registry);
    }
}
