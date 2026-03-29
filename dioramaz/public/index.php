<?php
/**
 * Start of Selection
 * Get the path from the request URL
 **/
require_once __DIR__ . '/../handlers/forge-collect.php';
dioramaz_forge_load_env(__DIR__ . '/../.env');

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = $requestPath;

/** buy/ routes: redirect via affiliate links JSON **/
if (str_starts_with($path, '/buy/')) {
    require_once __DIR__ . '/../handlers/buy-redirect.php';
    if (handleBuyRedirect($path, __DIR__ . '/buy-affiliate-links.json')) {
        exit;
    }
}
/** Replace "-" with "_" in the path **/
$path = trim(str_replace(['-', '/'], '_', $path), '_');
$path = $path != '' ? $path : 'home';

$file = '../storage/pages/';
/** Check if pagination needs to be taken care off **/
$page = $_GET['page'] ?? null;
if ($page) {
    $file .=  implode("_", [$path, 'page', $page]) . '.php';
    if (file_exists($file)) {
        readfile($file);
        dioramaz_forge_dispatch_after_response([
            'et' => 'pv',
            'path' => $requestPath,
        ]);
        return;
    }
}

/** If no pagination or paginated file doesn't exist continue **/
$file = '../storage/pages/' . $path . '.php';

/** Check if the file exists **/
if (file_exists($file)) {
    if ($path == 'page_not_found') {
        /** Set correct header if it's page 404 **/
        header("HTTP/1.0 404 Not Found");
    }
    /** Return the content of the file to the browser **/
    readfile($file);
} else {
    /** Return a 404 response if the file does not exist **/
    header("HTTP/1.0 404 Not Found");
    header("Location: page-not-found");
}
dioramaz_forge_dispatch_after_response([
    'et' => 'pv',
    'path' => $requestPath,
]);
