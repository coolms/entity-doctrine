<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Attribute;

use Attribute;

/**
 * Declare the discriminator value for a SINGLE_TABLE inheritance entity.
 *
 * Place this attribute on the root entity and every subclass.
 * The DiscriminatorValueSubscriber reads it at boot-time and
 * dynamically populates Doctrine's discriminatorMap without creating
 * a cross-bundle dependency on the parent mapping class.
 *
 * Usage:
 *   #[DiscriminatorValue('user')]
 *   class User implements UserInterface { ... }
 *
 *   #[DiscriminatorValue('oauth2_user')]
 *   class OAuth2User extends User { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class DiscriminatorValue
{
    public function __construct(public string $value)
    {
    }
}
