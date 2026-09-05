<?php

declare(strict_types=1);

use App\Config\AppConfig;
use App\Config\Routes;
use App\Core\Kernel;

// 1. Load Composer Autoloader
require __DIR__ . '/../vendor/autoload.php';

// 2. Load Configuration and Routes manually
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/routes.php';

// 3. NORMALIZE URL FOR SUBDIRECTORY DEPLOYMENTS
// این بخش باعث می‌شود هسته سیستم متوجه ساب‌فولدر نشود و مسیرها را استاندارد بخواند
$subfolder = '/b';
if (isset($_SERVER['REQUEST_URI']) && str_starts_with($_SERVER['REQUEST_URI'], $subfolder)) {
    $_SERVER['REQUEST_URI'] = substr($_SERVER['REQUEST_URI'], strlen($subfolder));
}

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