<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Tests\Event;

use CoolMS\Entity\Attribute\DiscriminatorValue;
use CoolMS\Entity\Doctrine\Attribute\DiscriminatorValue as LegacyDiscriminatorValue;
use CoolMS\Entity\Doctrine\Event\DiscriminatorValueSubscriber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionObject;

/**
 * DiscriminatorValue moved from CoolMS\Entity\Doctrine\Attribute to
 * CoolMS\Entity\Attribute on 2026-09-10, so that a subclass no longer inherits a
 * requirement on the ORM adapter merely by declaring what it is called.
 *
 * The old name survives as a class_alias. THAT ALIAS IS NOT SELF-EVIDENTLY
 * ENOUGH, which is why this file exists: an attribute is stored under the
 * literal class name written in the source, and reflection matches it against
 * the name the reader asks for. Measured before the move was made:
 *
 *   source says OLD, reader filters on NEW, plain filter  -> NOT FOUND
 *   source says OLD, reader filters on NEW, IS_INSTANCEOF -> found
 *
 * So the alias only works because DiscriminatorValueSubscriber passes
 * IS_INSTANCEOF. Remove either the alias or that flag and every consumer still
 * on the old name goes SILENTLY unmapped -- no error, just an entity missing
 * from the discriminator map. Both halves are asserted here.
 */
final class DiscriminatorValueAliasTest extends TestCase
{
    #[Test]
    public function theOldClassNameStillResolves(): void
    {
        self::assertTrue(
            class_exists(LegacyDiscriminatorValue::class),
            'the class_alias for the pre-2026-09-10 name is gone',
        );
        self::assertSame(
            DiscriminatorValue::class,
            new ReflectionClass(LegacyDiscriminatorValue::class)->getName(),
            'the old name resolves to something other than the moved class',
        );
    }

    /**
     * The assertion the move actually rests on: the READER, unmodified, finds a
     * class that still declares the old name.
     */
    #[Test]
    public function theSubscriberFindsAnAttributeWrittenUnderTheOldName(): void
    {
        $subscriber = new ReflectionClass(DiscriminatorValueSubscriber::class)
            ->newInstanceWithoutConstructor();
        $method = new ReflectionObject($subscriber)->getMethod('getDiscriminatorValueAttribute');

        $found = $method->invoke($subscriber, LegacyNamedDiscriminator::class);

        self::assertInstanceOf(
            DiscriminatorValue::class,
            $found,
            'a class declaring the pre-move attribute name is invisible to the subscriber',
        );
        self::assertSame('legacy_named', $found->value);
    }

    /**
     * Why the reader needs IS_INSTANCEOF, stated as a failing/passing pair
     * rather than as a comment. If PHP ever starts resolving aliases under the
     * plain filter, the first assertion breaks and the flag becomes optional --
     * which is worth being told about rather than discovering.
     */
    #[Test]
    public function thePlainFilterDoesNotResolveTheAliasButIsInstanceofDoes(): void
    {
        $reflected = new ReflectionClass(LegacyNamedDiscriminator::class);

        self::assertSame(
            [],
            $reflected->getAttributes(DiscriminatorValue::class),
            'the plain filter now resolves the alias -- the IS_INSTANCEOF flag in '
            . 'DiscriminatorValueSubscriber may no longer be load-bearing',
        );

        $viaInstanceof = $reflected->getAttributes(
            DiscriminatorValue::class,
            ReflectionAttribute::IS_INSTANCEOF,
        );
        self::assertCount(1, $viaInstanceof);
        self::assertSame('legacy_named', $viaInstanceof[0]->newInstance()->value);
    }

    /**
     * Control: the reader must still return null for a class carrying no such
     * attribute, or "found" above would mean nothing.
     */
    #[Test]
    public function aClassWithoutTheAttributeIsStillNotFound(): void
    {
        $subscriber = new ReflectionClass(DiscriminatorValueSubscriber::class)
            ->newInstanceWithoutConstructor();
        $method = new ReflectionObject($subscriber)->getMethod('getDiscriminatorValueAttribute');

        self::assertNull($method->invoke($subscriber, CarriesNoDiscriminator::class));
    }

    /**
     * Control: a class written against the NEW name is found too -- the move
     * would be pointless if the alias worked and the real name did not.
     */
    #[Test]
    public function theNewNameIsFoundAsWell(): void
    {
        $subscriber = new ReflectionClass(DiscriminatorValueSubscriber::class)
            ->newInstanceWithoutConstructor();
        $method = new ReflectionObject($subscriber)->getMethod('getDiscriminatorValueAttribute');

        $found = $method->invoke($subscriber, CurrentNamedDiscriminator::class);

        self::assertInstanceOf(DiscriminatorValue::class, $found);
        self::assertSame('current_named', $found->value);
    }
}

/**
 * Deliberately written against the PRE-MOVE name, imported once as
 * `LegacyDiscriminatorValue` so the spelling is stated in one place. Do not
 * "modernise" it -- it is the fixture, and the assertions above are about
 * exactly this name.
 *
 * The class exists only after the shim's class_alias has run, so static
 * analysis cannot see it; that is a property of the alias, not a defect here.
 *
 * @phpstan-ignore attribute.notFound
 */
#[LegacyDiscriminatorValue('legacy_named')]
final class LegacyNamedDiscriminator
{
}

#[DiscriminatorValue('current_named')]
final class CurrentNamedDiscriminator
{
}

final class CarriesNoDiscriminator
{
}
