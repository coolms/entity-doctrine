<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Upsert\Platform;

/**
 * SQLite (3.24+): `INSERT ... ON CONFLICT (cols) DO UPDATE SET col = excluded.col`.
 *
 * The dialect is byte-for-byte equivalent to PostgreSQL apart from the
 * lower-case `excluded` keyword (case-insensitive in practice). Anything
 * that compiles for Postgres compiles for SQLite, so the class extends
 * the Postgres impl rather than re-templating.
 */
final class SQLitePlatformUpsert extends PostgreSQLPlatformUpsert
{
}
