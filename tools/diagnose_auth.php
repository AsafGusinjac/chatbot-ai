<?php
/**
 * The endpoint path is confirmed correct and the token is recognised, but the
 * server 500s. This checks whether it expects the token in a different place
 * than `Authorization: Bearer`.
 *
 * Run:  C:\xampp\php\php.exe tools\diagnose_auth.php
 */

require __DIR__ . '/../config.php';

$token = config('digitalis_token');
$base  = 'https://digitalis.ba/api/brands';

$variants = [
    'Authorization: Bearer <t>' => [[ 'Authorization: Bearer ' . $token ], $base],
    'Authorization: <t>'        => [[ 'Authorization: ' . $token ], $base],
    'Authorization: Token <t>'  => [[ 'Authorization: Token ' . $token ], $base],
    'X-API-Key: <t>'            => [[ 'X-API-Key: ' . $token ], $base],
    'X-Auth-Token: <t>'         => [[ 'X-Auth-Token: ' . $token ], $base],
    'api_key: <t>'              => [[ 'api_key: ' . $token ], $base],
    '?token= query param'       => [[], $base . '?token=' . urlencode($token)],
    '?api_key= query param'     => [[], $base . '?api_key=' . urlencode($token)],
    'Bearer + JSON content-type'=> [[ 'Authorization: Bearer ' . $token, 'Content-Type: application/json' ], $base],
    'Bearer + browser UA'       => [[ 'Authorization: Bearer ' . $token, 'User-Agent: Mozilla/5.0' ], $base],
];

foreach ($variants as $label => [$headers, $url]) {
    $headers[] = 'Accept: application/json';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $clean = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');
    if ($clean === '') {
        $clean = '(empty body)';
    } elseif (mb_strlen($clean) > 70) {
        $clean = mb_substr($clean, 0, 70) . '…';
    }

    printf("  %-28s HTTP %-3d  %s\n", $label, $status, $clean);
}
