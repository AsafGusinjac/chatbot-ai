<?php
/**
 * Dumps the complete raw HTTP exchange for GET /api/products — request headers,
 * response headers, and the body byte-for-byte. Nothing interpreted.
 *
 * Run:  C:\xampp\php\php.exe tools\raw_products.php
 */

require __DIR__ . '/../config.php';

$token = config('digitalis_token');
$url   = 'https://digitalis.ba/api/products?page=1';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HEADER         => true,   // include response headers in the body
    CURLINFO_HEADER_OUT    => true,   // capture what we sent
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ],
]);

$raw    = (string) curl_exec($ch);
$info   = curl_getinfo($ch);
$err    = curl_error($ch);
curl_close($ch);

echo "REQUEST SENT\n", str_repeat('-', 60), "\n";
// Redact the token so this output is safe to paste into a bug report.
echo preg_replace('/(Authorization: Bearer )(\S+)/', '$1<REDACTED>', $info['request_header'] ?? '(none)');

echo "\nRESPONSE\n", str_repeat('-', 60), "\n";
if ($err !== '') {
    echo "curl error: {$err}\n";
    exit(1);
}

$headerSize = $info['header_size'] ?? 0;
$headers    = substr($raw, 0, $headerSize);
$body       = substr($raw, $headerSize);

echo $headers;
echo "BODY (", strlen($body), " bytes)\n", str_repeat('-', 60), "\n";
echo $body === '' ? "(completely empty — zero bytes returned)\n" : $body . "\n";

echo "\nTIMING: ", round(($info['total_time'] ?? 0) * 1000), " ms\n";
