<?php
declare(strict_types=1);
// Local PHP development server only; not a production routing configuration.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file($_SERVER['DOCUMENT_ROOT'] . $path)) { return false; }
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
