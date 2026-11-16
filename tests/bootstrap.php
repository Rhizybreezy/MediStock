<?php
/**
 * Test bootstrap. Integration tests use the configured database but wrap every
 * test in a transaction that is rolled back, so no data is left behind.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
App\Core\App::boot(dirname(__DIR__));
