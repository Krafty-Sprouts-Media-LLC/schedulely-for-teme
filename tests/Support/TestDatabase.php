<?php
/**
 * Filename: TestDatabase.php
 * Author: Krafty Sprouts Media, LLC
 * Description: A fresh in-memory SQLite database with the plugin's real migration run.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Tests\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Facade;

/**
 * Builds the database a store test runs against, with no Teme and no Laravel app.
 *
 * @since 0.1.0
 */
final class TestDatabase
{
    /**
     * @since 0.1.0
     */
    public static function fresh(): ConnectionInterface
    {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $container = $capsule->getContainer();
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_16_000001_create_schedulely_tables.php';
        $migration->up();

        return $capsule->getConnection();
    }
}
