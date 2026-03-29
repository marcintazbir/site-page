<?php

require_once __DIR__ . '/forge-collect.php';

/**
 * Buy affiliate redirect handler.
 * Loads buy-affiliate-links.json, picks entry by path and geo, then redirects.
 */

function handleBuyRedirect(string $path, string $jsonPath): bool
{
    $data = @json_decode(file_get_contents($jsonPath), true);
    if (!$data || !isset($data[$path])) {
        return false;
    }

    $geos = $data[$path];
    $geo = $_COOKIE['d_geo'] ?? null;
    $target = null;

    if ($geo && isset($geos[$geo])) {
        $target = $geos[$geo];
    }

    if (!$target) {
        $target = reset($geos);
    }

    if ($target && !empty($target['url'])) {
        header('Location: ' . $target['url'], true, 302);
        dioramaz_forge_dispatch_after_response([
            'et' => 'ep',
            'geo' => $geo,
            'target' => $target,
            'path' => $path,
        ]);
        exit;
    }

    return false;
}
