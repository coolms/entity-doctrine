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
 * Not exercised against a live server -- see the parent class docblock. The
 * generated SQL is asserted in CI.
 */
final class MariaDBPlatformUpsert extends MySQLPlatformUpsert
{
}
