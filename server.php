<?php

// Router for `php artisan serve` (PHP built-in server). Same as Laravel's own, plus one fix: for a path under an
// existing public folder that holds an index file (/iPanel/dashboard -> public/iPanel/index.html), the built-in server
// sets SCRIPT_NAME to that index file, so Laravel computes a wrong base URL and serves the home page instead of the
// admin. Point the script variables at public/index.php before handing the request to Laravel.

$publicPath = getcwd();

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

if ($uri !== '/' && is_file($publicPath.$uri)) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = $publicPath.'/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
unset($_SERVER['PATH_INFO']);

$formattedDateTime = date('D M j H:i:s Y');
file_put_contents('php://stdout', "[$formattedDateTime] {$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} [{$_SERVER['REQUEST_METHOD']}] URI: $uri\n");

require_once $publicPath.'/index.php';
