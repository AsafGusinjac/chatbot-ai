<?php
/**
 * Product search endpoint — this is what Make.com calls.
 *
 *   GET  /endpoint/search.php?q=satelitski+prijemnik&in_stock=1&limit=5
 *   POST {"q": "satelitski prijemnik", "in_stock": true, "limit": 5}
 *
 * Auth: send the shared token as `Authorization: Bearer <token>` or `?token=`.
 * This endpoint exposes purchase prices and stock levels, so it must not be
 * open to the internet.
 *
 * Response:
 *   { "count": 2, "results": [ {id, name, brand, price, stock, in_stock, …} ] }
 *
 * Target: PHP 7.4.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Http.php';
require_once __DIR__ . '/../lib/Text.php';
require_once __DIR__ . '/../lib/ProductSearch.php';

Http::jsonHeaders();

set_exception_handler(function ($e) {
    error_log('search.php uncaught: ' . $e->getMessage());
    Http::send(['error' => 'Search is temporarily unavailable.'], 500);
});

// --- Auth ------------------------------------------------------------------

Http::requireToken(api_token());

// --- Input -----------------------------------------------------------------

$input = Http::input();

$query = isset($input['q']) ? trim((string) $input['q']) : '';
if ($query === '') {
    Http::send(['error' => 'Missing search term (q).'], 400);
}
if (mb_strlen($query) > 200) {
    $query = mb_substr($query, 0, 200);
}

$options = [
    'limit'         => isset($input['limit']) ? (int) $input['limit'] : 8,
    'in_stock_only' => !empty($input['in_stock']),
];
if (!empty($input['category_id'])) {
    $options['category_id'] = (int) $input['category_id'];
}
if (!empty($input['supercategory_id'])) {
    $options['supercategory_id'] = (int) $input['supercategory_id'];
}
if (!empty($input['subcategory_id'])) {
    $options['subcategory_id'] = (int) $input['subcategory_id'];
}
if (!empty($input['max_price'])) {
    $options['max_price'] = (float) $input['max_price'];
}
if (!empty($input['min_price'])) {
    $options['min_price'] = (float) $input['min_price'];
}
if (!empty($input['target_price'])) {
    $options['target_price'] = (float) $input['target_price'];
}
if (!empty($input['sort'])) {
    $options['sort'] = (string) $input['sort'];
}
if (!empty($input['action_only']) || !empty($input['action']) || !empty($input['on_action'])) {
    $options['action_only'] = true;
}

// --- Search ----------------------------------------------------------------

try {
    $search = new ProductSearch(db());

    // A bare barcode is an exact lookup, not a text search.
    if (preg_match('/^\d{8,14}$/', $query)) {
        $exact = $search->findByEan($query);
        if ($exact !== null) {
            Http::send(['count' => 1, 'results' => [$exact], 'matched_by' => 'ean']);
        }
    }

    $results = $search->search($query, $options);
} catch (PDOException $e) {
    error_log('search.php database error: ' . $e->getMessage());
    Http::send(['error' => 'Search is temporarily unavailable.'], 503);
}

Http::send([
    'count'      => count($results),
    'results'    => $results,
    'matched_by' => 'text',
]);
