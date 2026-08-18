<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Listener;

use CoolMS\Entity\ExtrasProviderInterface;
use CoolMS\Entity\Registry\EntityAliasRegistry;
use CoolMS\Entity\Service\EntitySchemaLookup;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use InvalidArgumentException;

/**
 * Validates ExtrasProviderInterface entity extras against the alias's resolved
 * schema before every persist or update.
 *
 * The schema comes from {@see EntitySchemaLookup}, which merges static YAML
 * (`config/modules/*'/'fields/<alias>/<name>.yaml`) with record-defined fields
 * and, when a runtime-type module is installed, walks the type's inheritance
 * chain. Reading the merge rather than a runtime type's pre-built schema cache
 * is what lets a plain ExtrasProvider -- VFS Node, Contact, IdentityProfile --
 * have its required fields enforced at all; those aliases are not runtime types
 * and have no pre-built schema to consult.
 *
 * Enforcement is OPT-IN per alias (`entity.extras_validation`), because
 * enforcing an alias rejects writes that succeeded before. See
 * {@see \CoolMS\EntityBundle\DependencyInjection\Configuration}.
 *
 * `appliesTo` is honoured: a field scoped to a subset of instances is required
 * only of the instances it applies to. That is the difference between
 * getSchemaForInstance() and getSchemaForEntity(), and it matters -- vfs_node's
 * `title` is declared `appliesTo: {mimeType: text/x-dtmpl}`, so requiring it of
 * every directory and binary file would be wrong.
 *
 * Throws \InvalidArgumentException on constraint violation, which Doctrine
 * wraps and rolls the transaction back.
 */
final readonly class ExtrasValidationListener
{
    /**
     * @param string[] $aliases enforced aliases, or `['*']` for all
     * @param string[] $exclude aliases never enforced, even under `*`
     */
    public function __construct(
        private EntitySchemaLookup $schemaLookup,
        private EntityAliasRegistry $aliasRegistry,
        private array $aliases = [],
        private array $exclude = [],
    ) {
    }

    public function prePersist(PrePersistEventArgs $event): void
    {
        $this->validate($event->getObject());
    }

    public function preUpdate(PreUpdateEventArgs $event): void
    {
        $this->validate($event->getObject());
    }

    private function validate(object $entity): void
    {
        if (!$entity instanceof ExtrasProviderInterface) {
            return;
        }

        $alias = $this->aliasRegistry->aliasOf($entity::class);
        if (null === $alias || !$this->isEnforced($alias)) {
            return;
        }

        $extras = $entity->extras;
        $missing = [];

        foreach ($this->schemaLookup->getSchemaForInstance($entity) as $fieldName => $config) {
            if (($config['required'] ?? ($config['isRequired'] ?? false))
                && !array_key_exists($fieldName, $extras)
            ) {
                $missing[] = $fieldName;
            }
        }

        if ([] !== $missing) {
            throw new InvalidArgumentException(sprintf('ExtrasProvider validation failed for "%s": required field(s) missing from extras: %s.', $alias, implode(', ', $missing)));
        }
    }

    /**
     * `exclude` wins over `aliases` so that a blanket `*` can be rolled out
     * while a named alias is still being backfilled.
     */
    private function isEnforced(string $alias): bool
    {
        if (in_array($alias, $this->exclude, true)) {
            return false;
        }

        return in_array('*', $this->aliases, true) || in_array($alias, $this->aliases, true);
    }
}
