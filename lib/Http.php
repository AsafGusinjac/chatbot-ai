<?php
/**
 * Small helpers shared by the JSON endpoints.
 *
 * Target: PHP 7.4.
 */
class Http
{
    /**
     * Standard JSON response headers. Call once, before any output.
     *
     * @return void
     */
    public static function jsonHeaders()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }

    /**
     * Emit a JSON body and stop.
     *
     * @param array $data
     * @param int   $status
     * @return void
     */
    public static function send(array $data, $status = 200)
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * The bearer token presented by the caller.
     *
     * Falls back to a `token` query parameter, which is convenient for testing
     * but should not be used in production — query strings end up in access
     * logs.
     *
     * @return string
     */
    public static function bearerToken()
    {
        $header = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            // Some Apache configurations strip Authorization unless it is
            // explicitly passed through to PHP.
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (preg_match('/Bearer\s+(.+)/i', $header, $m)) {
            return trim($m[1]);
        }

        return isset($_GET['token']) ? (string) $_GET['token'] : '';
    }

    /**
     * Reject the request unless it carries the expected shared secret.
     *
     * Uses hash_equals so the comparison does not leak the token's prefix
     * through timing.
     *
     * @param string $expected
     * @return void
     */
    public static function requireToken($expected)
    {
        if ((string) $expected === '') {
            error_log('API token is not configured in config.local.php');
            self::send(['error' => 'Service is not configured.'], 503);
        }

        if (!hash_equals((string) $expected, self::bearerToken())) {
            self::send(['error' => 'Unauthorized.'], 401);
        }
    }

    /**
     * Decoded JSON request body merged over query parameters.
     *
     * @return array
     */
    public static function input()
    {
        $body = [];
        $raw  = file_get_contents('php://input');
        if ($raw !== '' && $raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        return array_merge($_GET, $body);
    }
}
