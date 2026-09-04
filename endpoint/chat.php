<?php
/**
 * Chat endpoint. Serves two kinds of caller:
 *
 * 1. The website widget (browser, same origin, no token).
 *    POST {"message":"..."}                 → {"reply":"...”}
 *    Identity comes from the PHP session; channel is "web".
 *    The browser CANNOT be given the API token — anyone could read it — so
 *    this mode is protected by an origin check plus per-IP rate limiting.
 *
 * 2. Server-to-server callers such as Make.com (Viber, WhatsApp, Messenger).
 *    POST {"channel":"viber","user_id":"abc","message":"..."}
 *    with  Authorization: Bearer <api_token>
 *
 * Sending `{"reset": true}` in either mode clears that conversation.
 *
 * Target: PHP 7.4.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Http.php';
require_once __DIR__ . '/../lib/Text.php';
require_once __DIR__ . '/../lib/ScopeGuard.php';
require_once __DIR__ . '/../lib/PromptLoader.php';
require_once __DIR__ . '/../lib/ChatApiException.php';
require_once __DIR__ . '/../lib/OpenAiApi.php';
require_once __DIR__ . '/../lib/ProductSearch.php';
require_once __DIR__ . '/../lib/ConversationStore.php';
require_once __DIR__ . '/../lib/ChatModel.php';
require_once __DIR__ . '/../lib/MockChatModel.php';
require_once __DIR__ . '/../lib/ChatService.php';
require_once __DIR__ . '/../lib/RateLimiter.php';

Http::jsonHeaders();

// The widget may be embedded on digitalis.ba while this endpoint lives
// elsewhere. Echo back only origins on the allow-list — never "*", which
// browsers reject outright when credentials are involved.
$origin  = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
$allowed = config_get('allowed_origins', []);

if ($origin !== '' && is_array($allowed) && in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}

applyStorefrontOriginOverrides($origin);

// Browsers send a preflight OPTIONS before a cross-origin JSON POST.
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

set_exception_handler(function ($e) {
    error_log('chat.php uncaught: ' . $e->getMessage());
    Http::send(['error' => 'Something went wrong on our end. Please try again.'], 500);
});

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    Http::send(['error' => 'Use POST.'], 405);
}

$input = Http::input();
applyStorefrontWebshopOverrides($input);

// --- Decide which mode this request is in -----------------------------------

$presented = Http::bearerToken();
$expected  = api_token();
$isServer  = ($presented !== '' && $expected !== '' && hash_equals($expected, $presented));

if ($presented !== '' && !$isServer) {
    // A token was offered but it was wrong. Do not silently downgrade to web
    // mode — that would turn a bad deployment into a hard-to-find bug.
    Http::send(['error' => 'Unauthorized.'], 401);
}

if ($isServer) {
    $channel = isset($input['channel']) ? strtolower(trim((string) $input['channel'])) : '';
    $userId  = isset($input['user_id']) ? trim((string) $input['user_id']) : '';

    if ($channel === '' || $userId === '') {
        Http::send(['error' => 'channel and user_id are required.'], 400);
    }

    $rateKey = $channel . ':' . $userId;
    $clientIp = RateLimiter::clientIp(config_get('trusted_proxies', []));
    $visitorRateKey = $rateKey;
} else {
    requireAllowedOrigin();

    $channel = 'web';
    $clientIp = RateLimiter::clientIp(config_get('trusted_proxies', []));

    // The embed sends a random id it keeps in localStorage. Prefer it: when the
    // widget is on another domain, third-party cookie blocking makes PHP
    // sessions unreliable, and many browsers now block them by default.
    $visitor = isset($input['visitor_id']) ? trim((string) $input['visitor_id']) : '';

    if ($visitor !== '' && preg_match('/^[A-Za-z0-9_-]{16,64}$/', $visitor)) {
        $userId = 'v_' . sha1($visitor);
    } else {
        // Same-origin page with no visitor id (the plain demo page): fall back
        // to a session cookie.
        session_start();
        $userId = 'sess_' . sha1(session_id());
    }

    // Rate limit by IP here, not by visitor id — the id comes from the client
    // and anyone could rotate it to get a fresh allowance.
    $rateKey = 'web:ip:' . $clientIp;
    $visitorRateKey = 'web:user:' . $userId;
}

// --- Feedback ---------------------------------------------------------------

if (!empty($input['feedback'])) {
    $feedbackLimiter = new RateLimiter(
        __DIR__ . '/../data/ratelimit/feedback',
        (int) config_get('feedback_rate_limit_max', 10),
        (int) config_get('feedback_rate_limit_window', 3600)
    );

    if (!$feedbackLimiter->allow($rateKey)) {
        Http::send(['error' => 'Previše ocjena je poslano. Pokušajte kasnije.'], 429);
    }

    storeConversationFeedback($input, $channel, $userId);
    Http::send(['ok' => true]);
}

// --- Rate limits ------------------------------------------------------------

if (empty($input['reset'])) {
    enforceRateLimit(
        __DIR__ . '/../data/ratelimit/burst',
        $rateKey,
        (int) config_get('burst_rate_limit_max', 6),
        (int) config_get('burst_rate_limit_window', 60),
        'Šaljete poruke prebrzo. Sačekajte malo pa pokušajte ponovo.'
    );

    enforceRateLimit(
        __DIR__ . '/../data/ratelimit/window',
        $rateKey,
        (int) config_get('rate_limit_max', 20),
        (int) config_get('rate_limit_window', 300),
        'Poslali ste dosta poruka. Sačekajte malo pa pokušajte ponovo.'
    );

    if ((bool) config_get('count_all_message_daily_limits', false)) {
        enforceRateLimit(
            __DIR__ . '/../data/ratelimit/visitor',
            $visitorRateKey,
            (int) config_get('visitor_daily_limit', 80),
            (int) config_get('visitor_daily_window', 86400),
            'Dostigli ste dnevni limit poruka za ovaj chat. Pokušajte ponovo kasnije.'
        );

        enforceRateLimit(
            __DIR__ . '/../data/ratelimit/daily',
            $rateKey,
            (int) config_get('ip_daily_limit', 120),
            (int) config_get('ip_daily_window', 86400),
            'Dostigli ste dnevni limit poruka. Pokušajte ponovo kasnije ili nas kontaktirajte telefonom.'
        );

        enforceRateLimit(
            __DIR__ . '/../data/ratelimit/global',
            'global:' . date('Y-m-d'),
            (int) config_get('global_daily_limit', 2000),
            (int) config_get('global_daily_window', 86400),
            'Chat je trenutno preopterećen. Pokušajte ponovo kasnije.'
        );
    }
}

// --- Configuration ----------------------------------------------------------

try {
    $systemPrompt = PromptLoader::load(__DIR__ . '/../prompts');
} catch (RuntimeException $e) {
    error_log('chat.php: ' . $e->getMessage());
    Http::send(['error' => 'The assistant is not configured yet.'], 503);
}

// Mock mode lets the whole system be exercised without spending anything on
// the API. It runs the real product search but fakes the language model, so
// replies are canned and carry a visible prefix.
if (config_get('use_mock_ai', false)) {
    $model = new MockChatModel((string) config_get('mock_prefix', '[MOCK] '));
} else {
    $apiKey = (string) config_get('openai_key', '');
    if ($apiKey === '') {
        error_log('chat.php: openai_key is empty in config.local.php');
        Http::send(['error' => 'The assistant is not configured yet.'], 503);
    }
    $temperature = config_get('openai_temperature', null);
    $model = new OpenAiApi(
        $apiKey,
        (string) config_get('openai_model', 'gpt-4o'),
        60,
        (bool) config_get('openai_max_completion_tokens', false),
        $temperature !== null ? (float) $temperature : null,
        config_get('openai_reasoning_effort', null)
    );
}

$service = new ChatService(
    db(),
    $model,
    $systemPrompt,
    (int) config_get('max_reply_tokens', 1024)
);

// --- Reset ------------------------------------------------------------------

if (!empty($input['reset'])) {
    $conversationId = $service->reset($channel, $userId);
    Http::send(['ok' => true, 'conversation_id' => $conversationId]);
}

// --- Reply ------------------------------------------------------------------

$message = isset($input['message']) ? (string) $input['message'] : '';
$maxMessageLength = max(1, (int) config_get('max_message_length', ChatService::MAX_MESSAGE_LENGTH));
if (mb_strlen(trim($message)) > $maxMessageLength) {
    Http::send([
        'error' => 'Poruka je preduga. Skratite je na najviše '
            . $maxMessageLength . ' karaktera i pokušajte ponovo.',
    ], 413);
}

$spamReason = messageAbuseReason($message);
if ($spamReason !== null) {
    error_log('chat.php rejected message before AI: ' . $spamReason . ' ip=' . $clientIp);
    Http::send([
        'error' => 'Poruka izgleda kao spam ili automatski zahtjev. Napišite kraće pitanje o artiklu koji tražite.',
    ], 422);
}

// Identity/appearance info the real site pushed via DstoreChat('identify', ...)
// (see public/embed.js's docblock) — forwarded to us as plain JSON fields.
// This is a client-supplied hint, not a server-verified fact: the only
// thing gated behind it is which catalog articles show up in chat search
// results, never pricing, checkout, or anything account-sensitive, so
// trusting the site's own JS here is an acceptable trade-off (same as any
// other value a browser sends us).
$visitor = [
    'wholesale_verified' => !empty($input['wholesale_hint']),
    'customer_name'      => isset($input['customer_name']) ? (string) $input['customer_name'] : '',
    'customer_id'        => isset($input['customer_id']) ? (string) $input['customer_id'] : '',
    'product_id'         => requestProductId($input),
    'product_name'       => isset($input['product_name']) ? (string) $input['product_name'] : '',
    'product_url'        => isset($input['product_url']) ? (string) $input['product_url'] : '',
    'product_action'     => isset($input['product_action']) ? (string) $input['product_action'] : '',
];

try {
    $result = $service->reply($channel, $userId, $message, $visitor);
} catch (InvalidArgumentException $e) {
    if ($e->getMessage() === 'Message too long.') {
        Http::send([
            'error' => 'Poruka je preduga. Skratite je na najviše '
                . $maxMessageLength . ' karaktera i pokušajte ponovo.',
        ], 413);
    }
    Http::send(['error' => 'Please type a message.'], 400);
} catch (ChatApiException $e) {
    error_log('chat.php OpenAI error [' . $e->getErrorType() . ']: ' . $e->getMessage());

    if (config_get('debug_errors', false)) {
        Http::send(['error' => 'DEBUG: ' . $e->getMessage()], 500);
    }
    if ($e->getErrorType() === 'refusal') {
        Http::send(['reply' => "I can't help with that one. Try asking me something about the store."], 200);
    }
    if ($e->isTransient()) {
        Http::send(['error' => 'The assistant is busy right now. Please try again in a moment.'], 503);
    }

    Http::send(['error' => 'The assistant is unavailable right now.'], 500);
} catch (PDOException $e) {
    error_log('chat.php database error: ' . $e->getMessage());
    Http::send(['error' => 'The assistant is temporarily unavailable.'], 503);
}

Http::send([
    'reply'           => $result['reply'],
    'conversation_id' => $result['conversation_id'],
    'products'        => isset($result['products']) ? $result['products'] : [],
    'cart_product'    => isset($result['cart_product']) ? $result['cart_product'] : null,
    'more_url'        => isset($result['more_url']) ? $result['more_url'] : null,
    // Short list of options the customer can tap instead of typing, e.g.
    // when a category ("Antene") has several real subtypes to choose from.
    'quick_replies'   => isset($result['quick_replies']) ? $result['quick_replies'] : [],
    // Brand options are visually richer than category chips: the widget can
    // render them as a horizontal slider with brand images.
    'brand_choices'   => isset($result['brand_choices']) ? $result['brand_choices'] : [],
    // The widget formats product-card prices itself (the reply text already
    // has the right currency baked in server-side, but the raw numeric price
    // fields do not). Sending the deployment's currency here means the
    // widget always labels prices correctly even if the embedding page
    // never sets data-currency.
    'currency'        => (string) config_get('currency', 'KM'),
]);

// ---------------------------------------------------------------------------

/**
 * In browser mode, only accept requests coming from our own site.
 *
 * This is not airtight — Origin can be forged by a non-browser client — but it
 * stops other websites from embedding our widget and spending our API credit,
 * which is the realistic abuse. Rate limiting handles the rest.
 *
 * @return void
 */
function requireAllowedOrigin()
{
    $allowed = config_get('allowed_origins', []);
    if (!is_array($allowed) || $allowed === []) {
        return;   // not configured: allow everything (fine for local testing)
    }

    $origin = '';
    if (!empty($_SERVER['HTTP_ORIGIN'])) {
        $origin = $_SERVER['HTTP_ORIGIN'];
    } elseif (!empty($_SERVER['HTTP_REFERER'])) {
        $parts = parse_url($_SERVER['HTTP_REFERER']);
        if (isset($parts['scheme'], $parts['host'])) {
            $origin = $parts['scheme'] . '://' . $parts['host'];
            if (isset($parts['port'])) {
                $origin .= ':' . $parts['port'];
            }
        }
    }

    if ($origin === '' || !in_array($origin, $allowed, true)) {
        error_log('chat.php: rejected web request from origin ' . ($origin === '' ? '(none)' : $origin));
        Http::send(['error' => 'Unauthorized.'], 403);
    }
}

/**
 * When one backend deployment on falcom.ba serves multiple storefronts, product
 * card URLs must still point back to the storefront where the widget is
 * embedded. Keep this tied to the already-checked browser Origin, not a
 * client-supplied "site" field.
 *
 * @param string $origin
 * @return void
 */
function applyStorefrontOriginOverrides($origin)
{
    $defaultOverrides = knownStorefrontOriginOverrides();

    $configured = config_get('storefront_origin_overrides', []);
    if (is_array($configured) && $configured !== []) {
        $defaultOverrides = array_merge($defaultOverrides, $configured);
    }

    if ($origin !== '' && isset($defaultOverrides[$origin]) && is_array($defaultOverrides[$origin])) {
        config_runtime_override($defaultOverrides[$origin]);
    }
}

/**
 * The embed script can explicitly say which storefront it belongs to:
 * data-webshop="dstore" -> POST {"webshop":"dstore"}. Only known keys are
 * accepted; this never trusts a browser-supplied URL.
 *
 * @param array $input
 * @return void
 */
function applyStorefrontWebshopOverrides(array $input)
{
    $webshop = isset($input['webshop']) ? strtolower(trim((string) $input['webshop'])) : '';
    if ($webshop === '') {
        return;
    }

    $defaultOverrides = knownStorefrontWebshopOverrides();

    $configured = config_get('storefront_webshop_overrides', []);
    if (is_array($configured) && $configured !== []) {
        $defaultOverrides = array_merge($defaultOverrides, $configured);
    }

    if (isset($defaultOverrides[$webshop]) && is_array($defaultOverrides[$webshop])) {
        config_runtime_override($defaultOverrides[$webshop]);
    }
}

/**
 * @return array<string,array<string,mixed>>
 */
function knownStorefrontWebshopOverrides()
{
    return [
        'digitalis' => [
            'shop_base_url' => 'https://www.digitalis.ba',
            'image_base_url' => 'https://www.digitalis.ba',
            'brand_image_base_url' => 'https://www.digitalis.ba',
            'shop_url_style' => 'webshop',
            'catalog_visibility_column' => 'is_mp',
            'catalog_wholesale_column' => 'is_vp',
            'faq_file' => 'faq.digitalis.txt',
            'store_name' => 'Digitalis',
            'assistant_name' => 'Digitalis AI',
            'currency' => 'KM',
            'installment_url' => '',
        ],
        'dstore' => [
            'shop_base_url' => 'https://www.dstore.ba',
            'image_base_url' => 'https://www.digitalis.ba',
            'brand_image_base_url' => 'https://www.digitalis.ba',
            'shop_url_style' => 'flat',
            'catalog_visibility_column' => 'is_mp',
            'catalog_wholesale_column' => '',
            'store_name' => 'D-Store',
            'assistant_name' => 'Dstore AI',
            'currency' => 'KM',
            'installment_url' => 'https://www.dstore.ba/kupovina-na-rate',
        ],
        'zed' => [
            'shop_base_url' => 'https://www.zed.hr',
            'image_base_url' => 'https://www.zed.hr',
            'brand_image_base_url' => 'https://www.zed.hr',
            'shop_url_style' => 'webshop',
            'catalog_visibility_column' => 'is_mp',
            'catalog_wholesale_column' => 'is_vp',
            'store_name' => 'Zed',
            'assistant_name' => 'Zed AI',
            'currency' => 'EUR',
        ],
        'optibox' => [
            'shop_base_url' => 'https://www.optibox.rs',
            'image_base_url' => 'https://www.optibox.rs',
            'brand_image_base_url' => 'https://www.optibox.rs',
            'shop_url_style' => 'webshop',
            'catalog_visibility_column' => 'is_mp',
            'catalog_wholesale_column' => 'is_vp',
            'store_name' => 'Optibox',
            'assistant_name' => 'Optibox AI',
            'currency' => 'RSD',
        ],
    ];
}

/**
 * @return array<string,array<string,mixed>>
 */
function knownStorefrontOriginOverrides()
{
    $byWebshop = knownStorefrontWebshopOverrides();

    return [
        'https://www.digitalis.ba' => $byWebshop['digitalis'],
        'https://digitalis.ba' => $byWebshop['digitalis'],
        'https://www.dstore.ba' => $byWebshop['dstore'],
        'https://dstore.ba' => $byWebshop['dstore'],
        'https://www.zed.hr' => $byWebshop['zed'],
        'https://zed.hr' => $byWebshop['zed'],
        'https://www.optibox.rs' => $byWebshop['optibox'],
        'https://optibox.rs' => $byWebshop['optibox'],
    ];
}

/**
 * @param array $input
 * @return int
 */
function requestProductId(array $input)
{
    if (isset($input['product_id']) && (int) $input['product_id'] > 0) {
        return (int) $input['product_id'];
    }

    $url = isset($input['product_url']) ? trim((string) $input['product_url']) : '';
    if ($url === '') {
        return 0;
    }

    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = $url;
    }

    if (preg_match('~/webshop/proizvod/(\d+)(?:/|$)~i', $path, $m)) {
        return (int) $m[1];
    }
    if (preg_match('~^/(\d{3,})(?:/|$)~', $path, $m)) {
        return (int) $m[1];
    }
    if (preg_match('~-(\d{4,8})/?$~', $path, $m)) {
        return (int) $m[1];
    }

    return 0;
}

/**
 * Enforce one named file-backed rate limit.
 *
 * @param string $dir
 * @param string $key
 * @param int    $max
 * @param int    $window
 * @param string $message
 * @return void
 */
function enforceRateLimit($dir, $key, $max, $window, $message)
{
    if ($max <= 0 || $window <= 0) {
        return;
    }

    $limiter = new RateLimiter($dir, $max, $window);
    if (!$limiter->allow($key)) {
        Http::send(['error' => $message], 429);
    }
}

/**
 * Reject obvious bot payloads before any OpenAI call is possible. Keep this
 * conservative: customers may paste one product URL or a messy product name,
 * so only block patterns that are very unlikely to be a real shopping question.
 *
 * @param string $message
 * @return string|null
 */
function messageAbuseReason($message)
{
    $message = (string) $message;
    if (trim($message) === '') {
        return null;
    }

    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $message)) {
        return 'control_chars';
    }

    $maxUrls = (int) config_get('max_message_urls', 2);
    if ($maxUrls >= 0 && preg_match_all('/https?:\/\/|www\./i', $message, $matches) > $maxUrls) {
        return 'too_many_urls';
    }

    $maxNewlines = (int) config_get('max_message_newlines', 20);
    if ($maxNewlines >= 0 && substr_count($message, "\n") > $maxNewlines) {
        return 'too_many_newlines';
    }

    $sameCharLimit = (int) config_get('max_repeated_character_run', 80);
    if ($sameCharLimit > 0 && preg_match('/(.)\1{' . $sameCharLimit . ',}/us', $message)) {
        return 'repeated_character';
    }

    $repeatedWordLimit = (int) config_get('max_repeated_word_run', 35);
    if ($repeatedWordLimit > 0 && preg_match('/\b([\p{L}\p{N}]{2,})\b(?:\s+\1\b){' . $repeatedWordLimit . ',}/iu', $message)) {
        return 'repeated_word';
    }

    $normalized = preg_replace('/\s+/u', '', Text::normalize($message));
    if (mb_strlen($normalized) >= 300) {
        $chars = [];
        $len = mb_strlen($normalized);
        for ($i = 0; $i < $len; $i++) {
            $chars[mb_substr($normalized, $i, 1)] = true;
            if (count($chars) > 8) {
                break;
            }
        }
        if (count($chars) <= 8) {
            return 'low_diversity';
        }
    }

    return null;
}

/**
 * Store a 1-5 conversation rating. This path must not construct/call the AI
 * model, because feedback collection should never spend API money.
 *
 * @param array  $input
 * @param string $channel
 * @param string $userId
 * @return void
 */
function storeConversationFeedback(array $input, $channel, $userId)
{
    $rating = isset($input['rating']) ? (int) $input['rating'] : 0;
    if ($rating < 1 || $rating > 5) {
        Http::send(['error' => 'Ocjena mora biti od 1 do 5.'], 400);
    }

    $comment = '';
    if (isset($input['comment'])) {
        $comment = trim((string) $input['comment']);
    } elseif (isset($input['feedback_text'])) {
        $comment = trim((string) $input['feedback_text']);
    }
    if (mb_strlen($comment) > 1000) {
        $comment = mb_substr($comment, 0, 1000);
    }

    $pageUrl = isset($input['page_url']) ? trim((string) $input['page_url']) : '';
    if (mb_strlen($pageUrl) > 1024) {
        $pageUrl = mb_substr($pageUrl, 0, 1024);
    }
    $webshop = feedbackWebshopKey($input, $pageUrl);

    $customerId = isset($input['customer_id']) ? trim((string) $input['customer_id']) : '';
    if (mb_strlen($customerId) > 191) {
        $customerId = mb_substr($customerId, 0, 191);
    }

    $customerName = isset($input['customer_name']) ? trim((string) $input['customer_name']) : '';
    if (mb_strlen($customerName) > 191) {
        $customerName = mb_substr($customerName, 0, 191);
    }

    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string) $_SERVER['HTTP_USER_AGENT']) : '';
    if (mb_strlen($ua) > 512) {
        $ua = mb_substr($ua, 0, 512);
    }

    $pdo = db();
    ensureFeedbackTable($pdo);

    $conversationId = null;
    try {
        $store = new ConversationStore($pdo);
        $conversationId = $store->getOrCreate($channel, $userId);
    } catch (Exception $e) {
        error_log('chat.php feedback conversation lookup failed: ' . $e->getMessage());
    }

    $stmt = $pdo->prepare(
        'INSERT INTO conversation_feedback
            (conversation_id, channel, external_id, webshop, rating, comment, page_url,
             user_agent, customer_id, customer_name, wholesale_hint)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $conversationId,
        (string) $channel,
        (string) $userId,
        $webshop,
        $rating,
        $comment !== '' ? $comment : null,
        $pageUrl !== '' ? $pageUrl : null,
        $ua !== '' ? $ua : null,
        $customerId !== '' ? $customerId : null,
        $customerName !== '' ? $customerName : null,
        !empty($input['wholesale_hint']) ? 1 : 0,
    ]);
}

/**
 * Existing cPanel deployments may not have the newest schema imported yet.
 * Lazily creating this one table keeps feedback from causing a 500.
 *
 * @param PDO $pdo
 * @return void
 */
function ensureFeedbackTable(PDO $pdo)
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS conversation_feedback (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            conversation_id BIGINT UNSIGNED NULL,
            channel         VARCHAR(32)  NOT NULL DEFAULT 'web',
            external_id     VARCHAR(191) NOT NULL DEFAULT '',
            webshop         VARCHAR(32)  NOT NULL DEFAULT '',
            rating          TINYINT UNSIGNED NOT NULL,
            comment         TEXT NULL,
            page_url        VARCHAR(1024) NULL,
            user_agent      VARCHAR(512) NULL,
            customer_id     VARCHAR(191) NULL,
            customer_name   VARCHAR(191) NULL,
            wholesale_hint  TINYINT(1) NOT NULL DEFAULT 0,
            created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_conversation (conversation_id),
            KEY idx_created (created_at),
            KEY idx_webshop (webshop),
            KEY idx_rating (rating)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    ensureFeedbackColumn($pdo, 'webshop', "VARCHAR(32) NOT NULL DEFAULT ''");
    ensureFeedbackIndex($pdo, 'idx_webshop', 'webshop');
}

/**
 * @param array  $input
 * @param string $pageUrl
 * @return string
 */
function feedbackWebshopKey(array $input, $pageUrl)
{
    $raw = isset($input['webshop']) ? strtolower(trim((string) $input['webshop'])) : '';
    if (in_array($raw, ['digitalis', 'dstore', 'zed', 'optibox'], true)) {
        return $raw;
    }

    $origin = isset($_SERVER['HTTP_ORIGIN']) ? strtolower((string) $_SERVER['HTTP_ORIGIN']) : '';
    $haystack = $origin . ' ' . strtolower((string) $pageUrl);

    if (strpos($haystack, 'dstore.ba') !== false || strpos($haystack, 'dstoredev.ba') !== false) {
        return 'dstore';
    }
    if (strpos($haystack, 'zed.hr') !== false) {
        return 'zed';
    }
    if (strpos($haystack, 'optibox.rs') !== false) {
        return 'optibox';
    }
    if (strpos($haystack, 'digitalis.ba') !== false) {
        return 'digitalis';
    }

    return '';
}

/**
 * @param PDO    $pdo
 * @param string $column
 * @param string $definition
 * @return void
 */
function ensureFeedbackColumn(PDO $pdo, $column, $definition)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM conversation_feedback LIKE " . $pdo->quote($column));
        if ($stmt !== false && $stmt->fetch() === false) {
            $pdo->exec("ALTER TABLE conversation_feedback ADD COLUMN `{$column}` {$definition}");
        }
    } catch (Exception $e) {
        error_log('chat.php feedback column migration failed: ' . $e->getMessage());
    }
}

/**
 * @param PDO    $pdo
 * @param string $index
 * @param string $column
 * @return void
 */
function ensureFeedbackIndex(PDO $pdo, $index, $column)
{
    try {
        $stmt = $pdo->query("SHOW INDEX FROM conversation_feedback WHERE Key_name = " . $pdo->quote($index));
        if ($stmt !== false && $stmt->fetch() === false) {
            $pdo->exec("ALTER TABLE conversation_feedback ADD KEY `{$index}` (`{$column}`)");
        }
    } catch (Exception $e) {
        error_log('chat.php feedback index migration failed: ' . $e->getMessage());
    }
}
