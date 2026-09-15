# Changelog

All notable changes to `coolms/entity-doctrine` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning is described in `CONTRIBUTING.md` -- read it before assuming what a
major number means here.

!! Entries dated before 2026-09-01 were **reconstructed** from tags and commit
history when this file was created. Every entry after that is written in the
same commit as the change it describes.

## Unreleased

### Added

Tests the application had been carrying for this package since the code
moved here: `MySQLPlatformSchemaManagerTest`, `PostgreSQLPlatformSchemaManagerTest`, `SQLitePlatformSchemaManagerTest`, `DiscriminatorValueAliasTest`. Nothing under `src/` changes.

## 2.0.0-alpha3 - 2026-09-10
### Deprecated

- `CoolMS\Entity\Doctrine\Attribute\DiscriminatorValue`. The class moved to
  `CoolMS\Entity\Attribute\DiscriminatorValue` in `coolms/entity`; the old name
  remains as an alias and keeps working.
- The move is about inheritance. An attribute placed on an entity is acquired
  by every subclass, so while the class lived here, a package declaring a
  subclass required this ORM adapter merely to state its own discriminator
  value -- and so did any third-party module extending such an entity. The
  reader that acts on the attribute stays in this package, where the Doctrine
  is.

### Changed

- `DiscriminatorValueSubscriber` matches the attribute with
  `ReflectionAttribute::IS_INSTANCEOF` rather than the plain filter. This is
  required, not cosmetic: an attribute is stored under the class name written
  in the source, the plain filter compares that name exactly, and it therefore
  does not resolve an alias. Measured -- a class declaring the old name is
  invisible to a plain filter on the new one. Without the flag the alias above
  would satisfy `class_exists()` and nothing else.
- The flag has a second effect worth knowing. When the filter class is absent
  altogether, the plain filter reports no attributes and raises nothing, so a
  mis-ordered install would map no subclasses at all and say so nowhere;
  `IS_INSTANCEOF` throws instead, at `loadClassMetadata`, on first boot.

### Requires

- `coolms/entity` 2.0.0-alpha3 or newer, because the attribute this package
  reads now lives there. Expressed as `conflict: coolms/entity <=2.0.0-alpha2`
  rather than a version floor: a floor naming an unreleased number refuses the
  published alphas and then resolves a development branch that lacks the class
  just the same, which is a constraint that looks strict and selects something
  broken.
- **That conflict entry is temporary.** It exists only while 2.0.0-alpha2 is
  still resolvable as a sibling. Remove it once alpha3 is the floor across the
  set, or it will sit here naming ancient history and reading as deliberate.

## 2.0.0-alpha2 - 2026-09-09
### Changed

- Follows the renamed application tier and the nested bundle namespaces.
  `CoolMS\Entity\Doctrine\` is unchanged.
- The "UNTESTED IN CI" notice is gone. It was stale, and stale in the direction
  that matters: it understated coverage that exists, which is the kind of note a
  reader acts on by writing a test that is already there.
- Says which segment carries the module rather than which namespace it sits in.
- Comments and docblocks are ascii; development-only files are export-ignored.

## 2.0.0-alpha1 - 2026-09-01

**A pre-release. It carries no compatibility promise**, which is the honest
statement of where the platform is: the shape is still moving, and a stable tag
would be a promise that cannot be kept yet.

Composer will not install it under default stability. Set

```json
"minimum-stability": "alpha",
"prefer-stable": true
```

in your root `composer.json`, then:

```
composer require coolms/entity-doctrine:^2.0
```

`prefer-stable` keeps every other dependency of yours on its newest stable
release, so this loosening applies to what actually needs it and nothing else.

!! **A per-package flag is not enough here.** `composer require
coolms/entity-doctrine:^2.0@alpha` admits the alpha of the package it names and
**nothing behind it**, so the siblings this one pulls in still fail to resolve.
Composer reports it against the sibling, not against what you asked for.

A bare `composer require coolms/entity-doctrine` resolves **successfully** to
v1.0.0 -- the previous generation -- and reports success while doing it.

Releases are suspended while development is moving fast and there are no
external consumers of these packages. This tag establishes the baseline the
documentation describes; nothing follows it until somebody outside the project
installs one, at which point the release policy resumes.

### The v2 generation -- a version number, and nothing else

This release moves `coolms/entity-doctrine` to `2.0.0` **without a single change to its
code**. Nothing was added, removed, renamed or fixed.

Every CoolMS platform package -- everything that requires `coolms/core` --
shares a major number, so that a set of packages carrying the same major is
known to work together. The whole set crosses to v2 at once, and this package
has nothing else in the crossing.

Before the shared major existed, `composer require coolms/entity-bundle`
resolved the entire set backwards onto its first generation -- including a
template engine from before output encoding existed -- and Composer reported
success. A shared major makes that resolution unreachable by accident.

**Upgrading: widen your constraint from `^1.0` to `^2.0`. There is nothing
else to do.** No class, signature, or behaviour changed. Breaks are announced as
deprecations in a minor and removed at a generation boundary; this boundary
removes none, because there were none to remove.

The standalone libraries published alongside the platform -- `coolms/rql`,
`coolms/rql-doctrine`, `coolms/dtmpl`, `coolms/dtmpl-bundle` -- do **not** take
this major. They have users who never touch CoolMS, and their numbers answer to
their own APIs.

### Changed: sibling constraints move to the v2 generation

- `coolms/core`: `^1.0` to `^2.0`
- `coolms/entity`: `^1.0` to `^2.0`
- `coolms/entity-persistence-implementation` (provided): `1.0` to `2.0`


The constraints on `coolms/rql` and `coolms/rql-doctrine` are unchanged. Those
are standalone libraries and do not take the platform generation.

## 1.0.0 - 2026-08-18

First release. Doctrine ORM and DBAL adapters for `coolms/entity`: the extras
mapping driver and validation listener, generated virtual columns, per-platform
schema and upsert managers, and the tree operators.
