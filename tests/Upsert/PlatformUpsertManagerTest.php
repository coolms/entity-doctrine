<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Tests\Upsert;

use CoolMS\Entity\Doctrine\Upsert\PlatformUpsertFactoryInterface;
use CoolMS\Entity\Doctrine\Upsert\PlatformUpsertInterface;
use CoolMS\Entity\Doctrine\Upsert\PlatformUpsertManager;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlatformUpsertManagerTest extends TestCase
{
    #[Test]
    public function resolvesPlatformImplLazilyAndMemoisesIt(): void
    {
        $connection = $this->createStub(Connection::class);
        $impl = $this->createMock(PlatformUpsertInterface::class);
        $impl->expects(self::once())->method('upsert')->willReturn(1);
        $impl->expects(self::once())->method('insertIfMissing')->willReturn(0);

        $factory = $this->createMock(PlatformUpsertFactoryInterface::class);
        // The factory must be hit exactly once across multiple calls --
        // the manager memoises the resolved impl on first use.
        $factory->expects(self::once())->method('create')->with($connection)->willReturn($impl);

        $manager = new PlatformUpsertManager($connection, $factory);
        $manager->upsert('t', ['k' => 'v'], ['k']);
        $manager->insertIfMissing('t', ['k' => 'v'], ['k']);
    }
}
