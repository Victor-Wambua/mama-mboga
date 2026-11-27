<?php

declare(strict_types=1);

// Minimal PSR-4 autoloader for the browser demo, where Composer's vendor/ is not present.
// The files are laid out in the virtual file system as /app/src and /app/demo/php.
spl_autoload_register(static function (string $class): void {
    $root = dirname(__DIR__, 2);
    $maps = ['MamaMboga\\Demo\\' => $root . '/demo/php/', 'MamaMboga\\' => $root . '/src/'];
    foreach ($maps as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
