<?php

declare(strict_types=1);

/*
 * MOVED 2026-09-10: CoolMS\Entity\Doctrine\Attribute\DiscriminatorValue
 *                -> CoolMS\Entity\Attribute\DiscriminatorValue
 *
 * REASON, recorded here because it will outlast anyone's memory of it:
 * an attribute placed on an entity is INHERITED BY EVERY SUBCLASS. While this
 * class lived in `coolms/entity-doctrine`, any package declaring a subclass --
 * `coolms/taxonomy`, and any third-party module extending a taxonomy node --
 * had to require the ORM adapter merely to state its own discriminator value.
 * The class imports nothing but `Attribute`; every line of Doctrine is in the
 * reader, {@see \CoolMS\Entity\Doctrine\Event\DiscriminatorValueSubscriber},
 * which stays in this package. Moving it let `coolms/taxonomy` drop
 * `coolms/entity-doctrine` from its manifest entirely, before that manifest was
 * ever tagged -- a tag cannot be edited, and an inherited dependency propagates
 * into consumers nobody here controls.
 *
 * This alias keeps `#[CoolMS\Entity\Doctrine\Attribute\DiscriminatorValue]`
 * working in code written against the old name.
 *
 * MEASURED, not assumed: `ReflectionClass::getAttributes($name)` with the PLAIN
 * filter does NOT resolve an alias -- a class declaring the old name is NOT
 * FOUND by a reader filtering on the new one. The subscriber therefore passes
 * `ReflectionAttribute::IS_INSTANCEOF`, which does resolve it. If that flag is
 * ever removed, every consumer still on the old name goes silently unmapped.
 * `DiscriminatorValueAliasTest` fails if either this alias or that flag goes.
 */

// The old name is given as a STRING, not ::class: it exists only once this
// line has run, so phpstan resolves ::class and reports class.notFound.
class_alias(
    CoolMS\Entity\Attribute\DiscriminatorValue::class,
    'CoolMS\Entity\Doctrine\Attribute\DiscriminatorValue',
);

@trigger_error(
    'CoolMS\Entity\Doctrine\Attribute\DiscriminatorValue is deprecated since 2026-09-10 '
    . 'and aliased to CoolMS\Entity\Attribute\DiscriminatorValue. It moved to the domain '
    . 'package because an attribute on an entity is inherited by every subclass, which '
    . 'forced a dependency on the ORM adapter onto anyone extending an entity that uses it.',
    E_USER_DEPRECATED,
);
