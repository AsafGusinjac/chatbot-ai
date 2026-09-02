<?php
/**
 * Loads config.local.php and fails loudly if it is missing.
 * Every other file gets its settings through this one.
 *
 * Target: PHP 7.4 (the version on dstore's server). Do not use PHP 8 syntax
 * here — see tools/check_php74.php for what that rules out.
 */

/**
 * @param string|null $key
 * @return mixed
 */
function config($key = null)
{
    static $config = null;

    if ($config === null) {
        // Which config file to load. Defaults to config.local.php; set the
        // CHATBOT_CONFIG env var (e.g. when launching the PHP built-in
        // server) to point a local test run at a different deployment's
        // config, such as config.local.zed.php, without touching the real
        // one. Production never sets this, so behaviour there is unchanged.
        $file = getenv('CHATBOT_CONFIG');
        $file = ($file !== false && $file !== '') ? $file : 'config.local.php';
        $path = __DIR__ . '/' . $file;
        if (!is_file($path)) {
            $msg = "Missing {$file}\n"
                 . "Copy config.local.example.php to config.local.php and fill it in.\n";
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, $msg);
            } else {
                error_log($msg);
            }
            exit(1);
        }
        $config = require $path;
    }

    $overrides = isset($GLOBALS['CHATBOT_CONFIG_OVERRIDES']) && is_array($GLOBALS['CHATBOT_CONFIG_OVERRIDES'])
        ? $GLOBALS['CHATBOT_CONFIG_OVERRIDES']
        : [];
    $effective = $overrides === [] ? $config : array_merge($config, $overrides);

    if ($key === null) {
        return $effective;
    }
    if (!array_key_exists($key, $effective)) {
        throw new RuntimeException("Unknown config key: {$key}");
    }
    return $effective[$key];
}

/**
 * Apply per-request config overrides after the HTTP origin/storefront is known.
 *
 * @param array $overrides
 * @return void
 */
function config_runtime_override(array $overrides)
{
    $current = isset($GLOBALS['CHATBOT_CONFIG_OVERRIDES']) && is_array($GLOBALS['CHATBOT_CONFIG_OVERRIDES'])
        ? $GLOBALS['CHATBOT_CONFIG_OVERRIDES']
        : [];
    $GLOBALS['CHATBOT_CONFIG_OVERRIDES'] = array_merge($current, $overrides);
}

/**
 * Like config(), but returns a default instead of throwing when the key is
 * absent. Use this for optional settings so an older config.local.php keeps
 * working after new keys are introduced.
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function config_get($key, $default = null)
{
    $all = config();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

/**
 * The shared secret Make.com presents to our endpoints.
 *
 * Reads `api_token`, falling back to the older `search_api_token` so existing
 * config files keep working.
 *
 * @return string
 */
function api_token()
{
    $token = (string) config_get('api_token', '');
    if ($token === '') {
        $token = (string) config_get('search_api_token', '');
    }
    return $token;
}

/**
 * PDO connection to the local MySQL database.
 *
 * @return PDO
 */
function db()
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            config('db_host'),
            config('db_name')
        );
        $pdo = new PDO($dsn, config('db_user'), config('db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    return $pdo;
}
