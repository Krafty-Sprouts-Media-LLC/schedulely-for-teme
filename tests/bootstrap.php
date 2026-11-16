<?php
/**
 * Filename: bootstrap.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Loads the development site's Composer autoloader (Pest, Illuminate
 *              and teme/contracts live there) and adds this package's own
 *              namespaces. Set SCHEDULELY_TEME_DEV to point at another Teme copy.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);
use Composer\Autoload\ClassLoader;

$temeDev = getenv('SCHEDULELY_TEME_DEV') ?: dirname(__DIR__, 2).'/teme-dev';
$autoload = $temeDev.'/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "Teme's vendor/autoload.php was not found at {$autoload}. Set SCHEDULELY_TEME_DEV.\n");
    exit(1);
}

/** @var ClassLoader $loader */
$loader = require $autoload;
$loader->addPsr4('KraftySprouts\\Schedulely\\', dirname(__DIR__).'/src/');
$loader->addPsr4('KraftySprouts\\Schedulely\\Tests\\', __DIR__.'/');
