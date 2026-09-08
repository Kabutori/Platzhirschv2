<?php
// Prepend live module sources to Composer's installed copies, only in local mode.
$loader = new Composer\Autoload\ClassLoader();
foreach (glob(dirname(__DIR__) . '/app/packages/*/composer.json') as $manifest) {
    $package = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
    foreach ($package['autoload']['psr-4'] ?? [] as $namespace => $directories) {
        foreach ((array) $directories as $directory) {
            $loader->addPsr4($namespace, dirname($manifest) . '/' . $directory);
        }
    }
}
$loader->register(true);
