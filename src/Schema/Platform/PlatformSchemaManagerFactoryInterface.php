<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Schema\Platform;

use Doctrine\DBAL\Connection;

interface PlatformSchemaManagerFactoryInterface
{
    /**
     * Creates the correct PlatformSchemaManagerInterface for the given DBAL connection.
     */
    public function create(Connection $connection): PlatformSchemaManagerInterface;
}
