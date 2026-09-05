<?php

declare(strict_types=1);

/**
 * Verification script — checks that the whole application is wired correctly.
 *
 * Usage:  php scripts/verify.php
 * Runs:   php -l on every PHP file, route -> controller -> method resolution,
 *         controller -> service -> method resolution, model toArray() presence,
 *         and repository INSERT column names against migration schemas.
 */

$root = __DIR__ . '/..';
$failures = 0;
$checks = 0;

// بارگذاری autoloader برای class_exists/method_exists
if (file_exists($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
}

function check(bool $condition, string $message): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures++;
        echo "  ✗ " . $message . PHP_EOL;
    }
}

function phpFiles(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $out[] = $file->getPathname();
        }
    }
    return $out;
}

echo "== 1) PHP syntax check (php -l) ==" . PHP_EOL;
$phpFiles = array_merge(
    phpFiles($root . '/src'),
    phpFiles($root . '/config'),
    [$root . '/public/index.php', $root . '/scripts/migrator.php'],
);
foreach ($phpFiles as $file) {
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $outLines, $code);
    check($code === 0, basename($file) . ': ' . implode(' ', $outLines));
}

echo "== 2) Routes -> Controllers -> Methods ==" . PHP_EOL;
require_once $root . '/config/routes.php';
$routes = \App\Config\Routes::$routes;
foreach ($routes as $route => $handler) {
    [$class, $method] = $handler;
    check(class_exists($class), "route '$route': controller class $class not found");
    if (class_exists($class)) {
        check(method_exists($class, $method), "route '$route': method $class::$method not found");
    }
}
echo "  checked " . count($routes) . " routes" . PHP_EOL;

echo "== 3) Controllers -> Services -> Methods ==" . PHP_EOL;
$controllerDir = $root . '/src/Http/Controllers';
foreach (glob($controllerDir . '/*.php') as $file) {
    $src = file_get_contents($file);
    preg_match_all('/new\s+(\w+Service)\(\)/', $src, $serviceMatches);
    foreach ($serviceMatches[1] as $serviceClass) {
        check(class_exists($serviceClass), basename($file) . ": service $serviceClass not found");
        if (!class_exists($serviceClass)) {
            continue;
        }
        preg_match_all('/\$[a-zA-Z]+->(\w+)\s*\(/', $src, $callMatches);
        foreach (array_unique($callMatches[1]) as $call) {
            if (in_array($call, ['setStatusCode', 'setJson'], true)) {
                continue;
            }
            check(method_exists($serviceClass, $call), basename($file) . ": $serviceClass::$call not found");
        }
    }
}

echo "== 4) Models -> toArray() ==" . PHP_EOL;
foreach (glob($root . '/src/Models/*.php') as $file) {
    $src = file_get_contents($file);
    $model = basename($file, '.php');
    check(str_contains($src, 'function toArray'), "model $model is missing toArray()");
}

echo "== 5) Repository INSERT columns vs Migration schemas ==" . PHP_EOL;
$schemas = [];
foreach (glob($root . '/database/migrations/*.php') as $migrationFile) {
    $src = file_get_contents($migrationFile);
    preg_match_all('/CREATE TABLE IF NOT EXISTS\s+(\w+)\s*\(/', $src, $tableMatches, PREG_OFFSET_CAPTURE);
    foreach ($tableMatches[1] as $m) {
        $table = $m[0];
        $pos = $m[1];
        $segment = substr($src, $pos, 4000);
        $end = strpos($segment, ') ENGINE');
        $segment = $end !== false ? substr($segment, 0, $end) : $segment;
        preg_match_all('/^\s*(\w+)\s/', $segment, $colMatches, PREG_MULTILINE);
        $skip = ['primary', 'unique', 'index', 'foreign', 'constraint', 'key'];
        $schemas[$table] = array_values(array_filter($colMatches[1], fn($c) => !in_array(strtolower($c), $skip, true)));
    }
}
foreach (glob($root . '/src/Repositories/*.php') as $file) {
    $src = file_get_contents($file);
    preg_match_all('/INSERT INTO\s+(\w+)\s*\(([^)]+)\)/', $src, $insertMatches, PREG_SET_ORDER);
    foreach ($insertMatches as $m) {
        $table = $m[1];
        $cols = array_map('trim', explode(',', $m[2]));
        check(isset($schemas[$table]), basename($file) . ": table $table not found in migrations");
        if (isset($schemas[$table])) {
            $missing = array_diff($cols, $schemas[$table]);
            check(empty($missing), basename($file) . ": columns " . implode(',', $missing) . " missing from $table schema");
        }
    }
}

echo PHP_EOL . "RESULT: $checks checks, $failures failure(s)" . PHP_EOL;
exit($failures > 0 ? 1 : 0);
