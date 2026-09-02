<?php
/**
 * Tries several URL shapes and auth-header variants against the Digitalis API
 * to work out which combination the server actually accepts.
 *
 * Prints status codes and a snippet of each body. Never prints the token.
 *
 * Run:  C:\xampp\php\php.exe tools\diagnose.php
 */

require __DIR__ . '/../config.php';

$token = config('digitalis_token');

echo "Token loaded: ", ($token && $token !== 'PASTE_YOUR_DIGITALIS_TOKEN_HERE') ? 'yes' : 'NO — still the placeholder!', "\n";
echo "Token length: ", strlen($token), " chars\n\n";

$urls = [
    'https://test.digitalis.ba/api/products?page=1',
    'https://test.digitalis.ba/api/api/products?page=1',
    'https://test.digitalis.ba/api/brands',
    'https://test.digitalis.ba/api/api/brands',
    'https://digitalis.ba/api/products?page=1',
    'https://digitalis.ba/api/brands',
];

foreach ($urls as $url) {
    echo str_repeat('-', 70), "\n", $url, "\n";
    foreach (['with token' => true, 'no token' => false] as $label => $useToken) {
        [$status, $body, $err, $ctype] = fetch($url, $useToken ? $token : null);
        printf("  %-11s HTTP %-3s %-24s %s\n",
            $label,
            $status ?: '-',
            $ctype ? substr($ctype, 0, 24) : '',
            $err ?: snippet($body)
        );
    }
}

/** @return array{0:int,1:string,2:string,3:string} status, body, error, content-type */
function fetch(string $url, ?string $token): array
{
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $body   = (string) curl_exec($ch);
    $err    = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    return [$status, $body, $err, $ctype];
}

function snippet(string $body): string
{
    $clean = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');
    if ($clean === '') {
        return '(empty body)';
    }
    return mb_strlen($clean) > 90 ? mb_substr($clean, 0, 90) . '…' : $clean;
}
