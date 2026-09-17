<?php
// Loads variables from a local .env file (if present) so config can be read the same
// way under XAMPP, Docker, and shared hosts. putenv() is disabled on some free hosts
// (e.g. InfinityFree) for security, so this does NOT rely on getenv()/putenv() to
// carry .env values - it parses them into a plain array and exposes them via env().
// Real server-injected environment variables (e.g. from docker-compose.yml) still take
// priority whenever they're actually present.

if (!defined('MOWLISS_ENV_LOADED')) {
    define('MOWLISS_ENV_LOADED', true);

    $GLOBALS['__mowliss_env'] = [];

    $envFile = __DIR__ . '/.env';

    if (is_file($envFile)) {
        $values = parse_ini_file($envFile, false, INI_SCANNER_RAW);

        if (is_array($values)) {
            $GLOBALS['__mowliss_env'] = $values;
        }
    }
}

function env(string $key, string $default = ''): string
{
    $real = getenv($key);
    if ($real !== false && $real !== '') {
        return $real;
    }

    $fromFile = $GLOBALS['__mowliss_env'][$key] ?? '';
    if ($fromFile !== '') {
        return $fromFile;
    }

    return $default;
}
