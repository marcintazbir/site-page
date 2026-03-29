<?php

/**
 * POST analytics payloads to DIORAMAZ_FORGE after the HTTP response is sent (non-blocking for the client under PHP-FPM).
 */

function dioramaz_forge_load_env(string $envPath): void
{
    static $attempted = false;
    if ($attempted) {
        return;
    }
    $attempted = true;
    if (!is_readable($envPath)) {
        return;
    }

    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name !== '') {
            $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }
    }
}

function dioramaz_forge_env_flag(string $name): bool
{
    $v = $_ENV[$name] ?? getenv($name);
    if ($v === false || $v === null || $v === '') {
        return false;
    }

    return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
}

/** Skip collect for static-like paths (substring match, case-insensitive). */
function dioramaz_forge_should_collect_path(string $requestPath): bool
{
    $p = strtolower($requestPath);
    foreach (['assets', 'imgs', '.js', '.css'] as $needle) {
        if (str_contains($p, $needle)) {
            return false;
        }
    }

    return true;
}

/**
 * Lowercase substrings; if any appear in User-Agent, treat as bot and skip collect.
 *
 * @return list<string>
 */
function dioramaz_forge_bot_user_agent_signatures(): array
{
    return [
        'googlebot',
        'adsbot-google',
        'feedfetcher-google',
        'mediapartners-google',
        'storebot-google',
        'google-read-aloud',
        'google-inspectiontool',
        'bingbot',
        'bingpreview',
        'msnbot',
        'slurp',
        'duckduckbot',
        'baiduspider',
        'yandexbot',
        'yandex.com/bots',
        'facebookexternalhit',
        'facebot',
        'twitterbot',
        'linkedinbot',
        'slackbot',
        'discordbot',
        'whatsapp',
        'telegrambot',
        'pinterestbot',
        'pinterest',
        'bytespider',
        'amazonbot',
        'applebot',
        'petalbot',
        'ia_archiver',
        'archive.org_bot',
        'semrushbot',
        'ahrefsbot',
        'ahrefs',
        'mj12bot',
        'dotbot',
        'serpstatbot',
        'megaindex',
        'seznam',
        'uptimerobot',
        'pingdom',
        'statuscake',
        'site24x7',
        'prerender',
        'chrome-lighthouse',
        'gtmetrix',
        'screaming frog',
        'python-requests/',
        'wget',
        'curl/',
        'libwww-perl',
        'httpunit',
        'nutch',
        'go-http-client',
        'apache-httpclient',
    ];
}

function dioramaz_forge_is_known_bot_user_agent(?string $userAgent): bool
{
    if ($userAgent === null || $userAgent === '') {
        return false;
    }

    $ua = strtolower($userAgent);
    foreach (dioramaz_forge_bot_user_agent_signatures() as $sig) {
        if (str_contains($ua, strtolower($sig))) {
            return true;
        }
    }

    return false;
}

/** Absolute URL for this request (scheme + host + REQUEST_URI), or empty if unknown. */
function dioramaz_forge_current_request_url(): string
{
    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    if ($host === '') {
        return '';
    }

    $https = ! empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if (is_string($forwarded) && $forwarded !== '') {
        $scheme = strtolower(trim(explode(',', $forwarded, 2)[0])) === 'https' ? 'https' : 'http';
    } else {
        $scheme = $https ? 'https' : 'http';
    }

    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';

    return $scheme . '://' . $host . $uri;
}

/**
 * @return array<string, string>
 */
function dioramaz_forge_request_context(): array
{
    $out = [];
    $current = dioramaz_forge_current_request_url();
    if ($current !== '') {
        $out['url'] = $current;
    }
    $referrer = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
    if ($referrer !== '') {
        $out['referrer'] = $referrer;
    }
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    if ($ua !== '') {
        $out['ua'] = $ua;
    }

    return $out;
}

/**
 * @param array<string, mixed> $payload Must include key "et" (pv | ep).
 */
function dioramaz_forge_dispatch_after_response(array $payload): void
{
    $forgeUrl = (string) ($_ENV['DIORAMAZ_FORGE_URL'] ?? getenv('DIORAMAZ_FORGE_URL') ?: '');
    $token = (string) ($_ENV['DIORAMAZ_FORGE_TOKEN'] ?? getenv('DIORAMAZ_FORGE_TOKEN') ?: '');
    $siteId = (string) ($_ENV['DIORAMAZ_SITE_ID'] ?? getenv('DIORAMAZ_SITE_ID') ?: '');
    if ($forgeUrl === '' || $token === '' || $siteId === '') {
        return;
    }

    $collectPath = (string) ($payload['path'] ?? '');
    if (! dioramaz_forge_should_collect_path($collectPath)) {
        return;
    }

    $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null;
    if (dioramaz_forge_is_known_bot_user_agent($userAgent)) {
        return;
    }

    $verifySsl = ! dioramaz_forge_env_flag('DIORAMAZ_FORGE_SSL_VERIFY_OFF');

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    try {
        $body = json_encode(array_merge(
            $payload,
            ['s_id' => $siteId],
            dioramaz_forge_request_context()
        ), JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($forgeUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ]);
        curl_exec($ch);
        curl_close($ch);

        return;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
            'content' => $body,
            'timeout' => 5,
        ],
        'ssl' => [
            'verify_peer' => $verifySsl,
            'verify_peer_name' => $verifySsl,
        ],
    ]);
    @file_get_contents($forgeUrl, false, $ctx);
}
