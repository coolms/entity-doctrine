<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Tests\Listener;

use CoolMS\Entity\Contract\FieldSchemaSourceInterface;
use CoolMS\Entity\Doctrine\Listener\ExtrasValidationListener;
use CoolMS\Entity\ExtrasProviderInterface;
use CoolMS\Entity\Registry\EntityAliasRegistry;
use CoolMS\Entity\Service\EntitySchemaLookup;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Which aliases the listener enforces, and what counts as a violation.
 *
 * The opt-in gate is the part worth unit-testing in isolation: enforcement
 * rejects writes that previously succeeded, so "is this alias opted in" is a
 * decision with real blast radius behind it, and `exclude` beating `*` is what
 * makes a staged rollout possible at all.
 *
 * Doctrine dispatch, the tag registration and the real YAML+record merge are
 * covered by the integration sibling in tests/Integration.
 */
final class ExtrasValidationListenerTest extends TestCase
{
    // -- the opt-in gate ----------------------------------------------------

    #[Test]
    public function noAliasIsEnforcedByDefault(): void
    {
        $listener = $this->makeListener(aliases: []);

        $this->expectNotToPerformAssertions();
        $listener->prePersist($this->event($this->node([])));
    }

    #[Test]
    public function anOptedInAliasIsEnforced(): void
    {
        $listener = $this->makeListener(aliases: ['vfs_node']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('required field(s) missing from extras: title');
        $listener->prePersist($this->event($this->node([])));
    }

    #[Test]
    public function anAliasThatIsNotOptedInIsUntouched(): void
    {
        $listener = $this->makeListener(aliases: ['some_other_alias']);

        $this->expectNotToPerformAssertions();
        $listener->prePersist($this->event($this->node([])));
    }

    #[Test]
    public function theWildcardEnforcesEveryAlias(): void
    {
        $listener = $this->makeListener(aliases: ['*']);

        $this->expectException(InvalidArgumentException::class);
        $listener->prePersist($this->event($this->node([])));
    }

    /**
     * The migration escape hatch: `*` can be switched on globally while one
     * un-backfilled alias is still exempt. If `exclude` did not win, a rollout
     * would be blocked on the slowest alias.
     */
    #[Test]
    public function excludeWinsOverTheWildcard(): void
    {
        $listener = $this->makeListener(aliases: ['*'], exclude: ['vfs_node']);

        $this->expectNotToPerformAssertions();
        $listener->prePersist($this->event($this->node([])));
    }

    #[Test]
    public function excludeWinsOverAnExplicitOptIn(): void
    {
        $listener = $this->makeListener(aliases: ['vfs_node'], exclude: ['vfs_node']);

        $this->expectNotToPerformAssertions();
        $listener->prePersist($this->event($this->node([])));
    }

    // -- what counts as a violation -----------------------------------------

    #[Test]
    public function aPresentRequiredFieldPasses(): void
    {
        $listener = $this->makeListener(aliases: ['vfs_node']);

        $this->expectNotToPerformAssertions();
        $listener->prePersist($this->event($this->node(['title' => 'anything'])));
    }

    /**
     * Presence, not truthiness. An explicit null / '' / 0 is a value the author
     * supplied; rejecting it here would silently duplicate NotBlank's job and
     * make a legitimately empty field impossible to save.
     */
    #[Test]
    public function aPresentButEmptyRequiredFieldPasses(): void
    {
        $listener = $this->makeListener(aliases: ['vfs_node']);

        $this->expectNotToPerformAssertions();
        $listener->prePersist($this->event($this->node(['title' => null])));
    }

    #[Test]
    public function anUnaliasedEntityIsIgnored(): void
    {
        $listener = $this->makeListener(aliases: ['*'], aliasMap: []);

        $this->expectNotToPerformAssertions();
        $listener->prePersist($this->event($this->node([])));
    }

    #[Test]
    public function aNonExtrasProviderIsIgnored(): void
    {
        $listener = $this->makeListener(aliases: ['*']);

        $this->expectNotToPerformAssertions();
        $listener->prePersist($this->event(new stdClass()));
    }

    /**
     * Both spellings appear in the wild: static YAML files carry `isRequired`,
     * while some schema shapes carry `required`. Dropping either silently
     * un-enforces every field that used it.
     */
    #[Test]
    public function bothRequiredSpellingsAreHonoured(): void
    {
        $listener = $this->makeListener(
            aliases: ['vfs_node'],
            fields: ['legacy' => ['required' => true]],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('legacy');
        $listener->prePersist($this->event($this->node([])));
    }

    #[Test]
    public function everyMissingFieldIsReportedNotJustTheFirst(): void
    {
        $listener = $this->makeListener(
            aliases: ['vfs_node'],
            fields: [
                'title' => ['isRequired' => true],
                'slug' => ['isRequired' => true],
                'optional' => ['isRequired' => false],
            ],
        );

        try {
            $listener->prePersist($this->event($this->node([])));
            self::fail('Expected the listener to reject the entity.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('title', $e->getMessage());
            self::assertStringContainsString('slug', $e->getMessage());
            self::assertStringNotContainsString('optional', $e->getMessage());
        }
    }

    // -- helpers ------------------------------------------------------------

    /**
     * @param string[]                                 $aliases
     * @param string[]                                 $exclude
     * @param array<string, array<string, mixed>>|null $fields
     * @param array<class-string, string>|null         $aliasMap
     */
    private function makeListener(
        array $aliases,
        array $exclude = [],
        ?array $fields = null,
        ?array $aliasMap = null,
    ): ExtrasValidationListener {
        $fields ??= ['title' => ['isRequired' => true]];
        $aliasMap ??= [FakeExtrasNode::class => 'vfs_node'];

        $source = new class($fields) implements FieldSchemaSourceInterface {
            /** @param array<string, array<string, mixed>> $fields */
            public function __construct(private readonly array $fields)
            {
            }

            public function getRuntimeFields(string $entityAlias): array
            {
                return 'vfs_node' === $entityAlias ? $this->fields : [];
            }
        };

        $registry = new EntityAliasRegistry($aliasMap);

        return new ExtrasValidationListener(
            schemaLookup: new EntitySchemaLookup(
                fieldSchemaSource: $source,
                configDir: '',
                modulesDir: '',
                typeContributor: null,
                aliasRegistry: $registry,
            ),
            aliasRegistry: $registry,
            aliases: $aliases,
            exclude: $exclude,
        );
    }

    /** @param array<string, mixed> $extras */
    private function node(array $extras): FakeExtrasNode
    {
        $node = new FakeExtrasNode();
        $node->extras = $extras;

        return $node;
    }

    private function event(object $entity): PrePersistEventArgs
    {
        return new PrePersistEventArgs($entity, $this->createStub(EntityManagerInterface::class));
    }
}

/**
 * @internal test-only ExtrasProvider
 */
final class FakeExtrasNode implements ExtrasProviderInterface
{
    /** @var array<string, mixed> */
    public array $extras = [];
}
