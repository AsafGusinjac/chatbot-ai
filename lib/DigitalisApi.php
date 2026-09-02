<?php
/**
 * Thin client for the Digitalis (dstore) REST API.
 *
 * Every response has the shape { success: 1|0, message: ..., data: ... },
 * so get() unwraps `data` and throws on anything else.
 *
 * Target: PHP 7.4. Typed properties are fine (added in 7.4); constructor
 * property promotion is not (8.0), so the assignments are written out.
 */
class DigitalisApi
{
    /** @var string */
    private $baseUrl;

    /** @var string */
    private $token;

    /**
     * @param string $baseUrl
     * @param string $token
     */
    public function __construct($baseUrl, $token)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token   = $token;
    }

    /**
     * GET a path such as '/products' and return the decoded `data` payload.
     *
     * @param string $path
     * @param array<string,scalar> $query
     * @return mixed
     */
    public function get($path, array $query = [])
    {
        $envelope = $this->request($path, $query);
        return isset($envelope['data']) ? $envelope['data'] : null;
    }

    /**
     * Same as get(), but returns the whole envelope so callers can read
     * pagination fields like current_page / total_pages.
     *
     * @param string $path
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     */
    public function getEnvelope($path, array $query = [])
    {
        return $this->request($path, $query);
    }

    /**
     * @param string $path
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     */
    private function request($path, array $query)
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
            ],
        ]);

        $body   = curl_exec($ch);
        $errNo  = curl_errno($ch);
        $errMsg = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0) {
            throw new RuntimeException("Network error calling {$url}: {$errMsg}");
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("HTTP {$status} from {$url}: " . substr((string) $body, 0, 500));
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Response from {$url} was not JSON: " . substr((string) $body, 0, 500));
        }

        // `success` is documented as 1/0 but APIs are inconsistent about types.
        if (isset($decoded['success']) && !filter_var($decoded['success'], FILTER_VALIDATE_BOOLEAN)) {
            $message = isset($decoded['message']) ? $decoded['message'] : 'no message';
            throw new RuntimeException("API reported failure for {$url}: " . json_encode($message));
        }

        return $decoded;
    }
}
