<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Upsert;

use Doctrine\DBAL\Connection;

interface PlatformUpsertFactoryInterface
{
    /**
     * Creates the correct {@see PlatformUpsertInterface} for the given
     * DBAL connection, matching the factory shape used by Ship A's
     * {@see \CoolMS\Entity\Doctrine\Schema\Platform\PlatformSchemaManagerFactoryInterface}.
     */
    public function create(Connection $connection): PlatformUpsertInterface;
}
