<?php
/**
 * MediStock Intelligence - front controller.
 * Every HTTP request (except real asset files) is routed through here.
 * Nothing outside /public is web-accessible.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

App\Core\App::boot(dirname(__DIR__))->run();
