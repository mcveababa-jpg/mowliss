<?php
// Loads variables from a local .env file (if present) into getenv()/$_ENV,
// without overriding any variable already set by the real environment
// (e.g. the ones docker-compose.yml injects into the container).
// This lets the same codebase read config the same way under XAMPP and Docker.

if (!defined('MOWLISS_ENV_LOADED')) {
    define('MOWLISS_ENV_LOADED', true);

    $envFile = __DIR__ . '/.env';

    if (is_file($envFile)) {
        $values = parse_ini_file($envFile, false, INI_SCANNER_RAW);

        if (is_array($values)) {
            foreach ($values as $key => $value) {
                if (getenv($key) === false) {
                    putenv("{$key}={$value}");
                    $_ENV[$key] = $value;
                }
            }
        }
    }
}
