<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;

$files = glob(__DIR__ . '/migrations/*.php');
sort($files);

$action = $argv[1] ?? 'up';

foreach ($files as $file) {
    if (basename($file) === basename(__FILE__)) {
        continue;
    }
    echo "Running: " . basename($file) . " ... ";
    require $file;
    echo "Done.\n";
}

echo "Migrations completed.\n";
