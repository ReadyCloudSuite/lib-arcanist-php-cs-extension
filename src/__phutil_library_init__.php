<?php

$vendorDir = null;
$possiblePaths = [
    __DIR__ . '/../vendor/',
    __DIR__ . '/../../../../vendor/',
    __DIR__ . '/../../../../../vendor/',
];

foreach ($possiblePaths as $path) {
    if (file_exists($path . 'ptlis/diff-parser/src/Parser.php')) {
        $vendorDir = $path;
        break;
    }
}

spl_autoload_register(function($className) use ($vendorDir) {
    if ($vendorDir === null) {
        return;
    }
    if (strpos($className, 'ptlis\\DiffParser\\') !== false) {
        $subPath = strtr(substr($className, strlen('ptlis\DiffParser\\')), ['\\' => '/']);
        require_once $vendorDir . 'ptlis/diff-parser/src/' . $subPath . '.php';
    }
});

phutil_register_library('php-cs-fixer-lint-engine', __FILE__);
