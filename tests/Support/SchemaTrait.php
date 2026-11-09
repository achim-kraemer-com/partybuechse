<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Rebuilds the (SQLite) test schema from the entity mapping before each test.
 */
trait SchemaTrait
{
    protected function resetSchema(EntityManagerInterface $entityManager): void
    {
        $tool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }
}
