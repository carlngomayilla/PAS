<?php

$projectRoot = __DIR__;
$publicPath = $projectRoot.'/public';

if (! is_dir($publicPath)) {
    $publicPath = getcwd();
}

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

if ($uri !== '/' && file_exists($publicPath.$uri)) {
    return false;
}

require_once $publicPath.'/index.php';
