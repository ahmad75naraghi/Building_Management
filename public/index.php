<?php

declare(strict_types=1);

use App\Config\AppConfig;
use App\Config\Routes;
use App\Core\Kernel;

require __DIR__ . '/../vendor/autoload.php';

// Bootstrap error reporting based on environment
if (AppConfig::isProduction()) {
    error_reporting(0);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Initialize the Kernel and handle the request
$kernel = new Kernel();
$response = $kernel->handle();
$response->send();
