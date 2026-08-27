# Changelog

All notable changes to `coolms/entity-doctrine` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning is described in `CONTRIBUTING.md` -- read it before assuming what a
major number means here.

⚠️ Entries dated before 2026-09-01 were **reconstructed** from tags and commit
history when this file was created. Every entry after that is written in the
same commit as the change it describes.

## Unreleased -- 2.0.0

Rides the next Tuesday release train. Nothing here has shipped yet.

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
