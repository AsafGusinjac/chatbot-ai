<?php
/**
 * Sliding-window rate limiter backed by files.
 *
 * A public chat endpoint that calls a paid API is an open invitation to run up
 * a bill. This caps how many messages one visitor can send per window.
 *
 * File-based rather than session-based on purpose: a session cookie is trivial
 * to discard, so a session limit stops honest users and nobody else.
 *
 * Target: PHP 7.4.
 */
class RateLimiter
{
    /** @var string */
    private $storageDir;

    /** @var int */
    private $maxRequests;

    /** @var int */
    private $windowSeconds;

    /**
     * @param string $storageDir    Writable directory for the counters.
     * @param int    $maxRequests   Allowed requests per window.
     * @param int    $windowSeconds Window length in seconds.
     */
    public function __construct($storageDir, $maxRequests = 20, $windowSeconds = 300)
    {
        $this->storageDir    = rtrim($storageDir, '/\\');
        $this->maxRequests   = $maxRequests;
        $this->windowSeconds = $windowSeconds;

        if (!is_dir($this->storageDir) && !@mkdir($this->storageDir, 0775, true) && !is_dir($this->storageDir)) {
            throw new RuntimeException("Cannot create rate limit directory: {$this->storageDir}");
        }
    }

    /**
     * Record a request for this key and report whether it is allowed.
     *
     * @param string $key Usually the client IP.
     * @return bool True if the request may proceed.
     */
    public function allow($key)
    {
        $file = $this->storageDir . DIRECTORY_SEPARATOR . sha1($key) . '.json';
        $now  = time();

        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            // If the limiter cannot write, fail open rather than break the chat.
            error_log("RateLimiter: cannot open {$file}");
            return true;
        }

        try {
            flock($handle, LOCK_EX);

            $contents  = stream_get_contents($handle);
            $timestamps = json_decode((string) $contents, true);
            if (!is_array($timestamps)) {
                $timestamps = [];
            }

            // Drop anything outside the window.
            $cutoff = $now - $this->windowSeconds;
            $recent = [];
            foreach ($timestamps as $ts) {
                if (is_int($ts) && $ts > $cutoff) {
                    $recent[] = $ts;
                }
            }

            $allowed = count($recent) < $this->maxRequests;
            if ($allowed) {
                $recent[] = $now;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($recent));
            fflush($handle);

            return $allowed;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Seconds until the caller may retry. Zero when they are not limited.
     *
     * @param string $key
     * @return int
     */
    public function retryAfter($key)
    {
        $file = $this->storageDir . DIRECTORY_SEPARATOR . sha1($key) . '.json';
        if (!is_file($file)) {
            return 0;
        }

        $timestamps = json_decode((string) @file_get_contents($file), true);
        if (!is_array($timestamps) || $timestamps === []) {
            return 0;
        }

        sort($timestamps);
        $oldest = (int) $timestamps[0];
        $retry  = ($oldest + $this->windowSeconds) - time();

        return $retry > 0 ? $retry : 0;
    }

    /**
     * Best guess at the client IP.
     *
     * Only trusts proxy headers when the request actually arrives from a proxy
     * you have listed — otherwise anyone can spoof X-Forwarded-For and bypass
     * the limit entirely.
     *
     * @param array $trustedProxies IPs of your own reverse proxies, if any.
     * @return string
     */
    public static function clientIp(array $trustedProxies = [])
    {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';

        if ($trustedProxies !== [] && in_array($remote, $trustedProxies, true)) {
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $first = trim($parts[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
        }

        return $remote;
    }
}
