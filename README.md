# coolms/entity-doctrine

[![CI](https://github.com/coolms/entity-doctrine/actions/workflows/ci.yml/badge.svg)](https://github.com/coolms/entity-doctrine/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/coolms/entity-doctrine)](https://packagist.org/packages/coolms/entity-doctrine)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.5-777bb4)](https://www.php.net/releases/8.5/en.php)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

Doctrine ORM/DBAL adapters for [`coolms/entity`](https://packagist.org/packages/coolms/entity).
Provides the virtual package `coolms/entity-persistence-implementation`.

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
