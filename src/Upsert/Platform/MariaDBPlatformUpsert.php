<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Upsert\Platform;

/**
 * MariaDB shares MySQL's `INSERT ... ON DUPLICATE KEY UPDATE` syntax
 * verbatim, so the template is identical. Kept as a distinct class
 * for symmetry with Ship A's {@see \CoolMS\Entity\Doctrine\Schema\Platform\MariaDBPlatformSchemaManager}
 * and so the factory's `MariaDBPlatform`-before-`MySQLPlatform` match
 * order resolves to the right marker class.
 *
 * MariaDBPlatform extends MySQLPlatform in Doctrine DBAL 4.x, so the
 * factory MUST test MariaDB first.
 *
 * UNTESTED IN CI -- see the parent class docblock.
 */
final class MariaDBPlatformUpsert extends MySQLPlatformUpsert
{
}
