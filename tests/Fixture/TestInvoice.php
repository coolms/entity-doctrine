<?php

declare(strict_types=1);

namespace CoolMS\Entity\Doctrine\Tests\Fixture;

use CoolMS\Core\Attribute\ClassMeta;

#[ClassMeta(label: 'Test Invoice', alias: 'invoice', aliasCollection: 'invoices')]
final class TestInvoice
{
}
