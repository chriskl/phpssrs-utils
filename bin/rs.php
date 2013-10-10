<?php

// Use composer's autoload.php if available
if (file_exists(dirname(__FILE__) . '/../vendor/autoload.php')) {
    require_once dirname(__FILE__) . '/../vendor/autoload.php';
} else {
    if (file_exists(dirname(__FILE__) . '/../../../autoload.php')) {
        require_once dirname(__FILE__) . '/../../../autoload.php';
    }
}

// Set any INI options for PHP
// ---------------------------

/* set include paths */
set_include_path(
    dirname(__FILE__) . '/../library' .
    PATH_SEPARATOR .
    get_include_path()
);


require_once 'PhpSsrsUtils/Rsc.php';

try {
    // Grab and clean up the CLI arguments
    $args = isset($argv) ? $argv : $_SERVER['argv'];
    array_shift($args); // 1st arg is script name, so drop it

    Rs::execute($args);
} catch (Exception $x) {
    exit(1);
}
