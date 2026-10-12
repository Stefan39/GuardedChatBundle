<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;

require __DIR__.'/../../vendor/autoload.php';

// Gives phpstan-doctrine a metadata-aware entity manager so query results get real types.
$config = ORMSetup::createAttributeMetadataConfig([__DIR__.'/../../src/Entity'], true);
$config->enableNativeLazyObjects(true);

return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
