# coolms/entity-doctrine

[![CI](https://github.com/coolms/entity-doctrine/actions/workflows/ci.yml/badge.svg)](https://github.com/coolms/entity-doctrine/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/coolms/entity-doctrine)](https://packagist.org/packages/coolms/entity-doctrine)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.5-777bb4)](https://www.php.net/releases/8.5/en.php)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

Doctrine ORM/DBAL adapters for [`coolms/entity`](https://packagist.org/packages/coolms/entity).
Provides the virtual package `coolms/entity-persistence-implementation`.

> ⚠️ **`provide` is a placeholder, as of 2026-09-16.** Declared; read by
> Composer's resolver alone, when `coolms/entity-application` asks for the
> virtual name (a consumer must name this package -- Composer will not pick a
> provider by itself); read by no code. No selector exists and no second adapter
> exists: Doctrine is the only persistence today, and a second (Cycle, or an
> in-house ORM) is intended rather than planned. And for THIS family the manifest
> promises more than the code keeps: `coolms/entity-bundle` imports eleven classes
> of this package by name -- in its DI extension and two compiler passes -- so
> providing the same virtual name would not be enough to substitute; the bundle
> would have to change with it. The line becomes live when a second adapter
> exists AND the wiring moves into the adapter, the way `coolms/core-doctrine`
> ships its own bundle and `coolms/core-bundle` imports nothing of it. Until then
> the true property of this package is narrower and still worth having:
> `coolms/entity` itself imports no `Doctrine\ORM\` or `Doctrine\DBAL\` class, so
> replacing the ORM would not touch the domain.

- `Mapping\ExtrasFieldMappingDriver` -- decorates the central metadata driver to
  surface generated `v_{name}` virtual columns, so extras fields are filterable
  and sortable through an index rather than a JSON scan. Uses a DBAL connection
  rather than an ORM repository, because the ORM needs metadata to boot.
- `Mapping\TraitMappingDriver` -- reads column attributes declared on traits.
- `Listener\ExtrasValidationListener` -- enforces `required` extras fields on
  persist and update. Opt-in per alias: enforcing an alias rejects writes that
  succeeded before, so it is a data migration rather than a flag flip.
- `Schema\Platform\*`, `Upsert\Platform\*` -- per-platform DDL and upsert
  emitters (PostgreSQL, MySQL, MariaDB, SQLite, SQL Server, Oracle).
- `Tree\*` -- nested-set and materialized-path operators. Registered explicitly
  per consuming module: the constructors take the entity class and tree
  expressions, which autowiring cannot fill.
- `Repository\DoctrineEntitySchemaProvider` -- the only class that reads Doctrine
  ORM metadata for entity introspection; everything above it depends on the
  `EntitySchemaProviderInterface` contract.

## Installation

```bash
composer require coolms/entity-doctrine
```
