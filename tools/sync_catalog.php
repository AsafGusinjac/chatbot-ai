<?php
/**
 * Mirrors the Digitalis catalog into local MySQL.
 *
 * The products feed is one ~11.7 MB response containing all 10,692 products —
 * `page` is accepted but ignored, and there is no pagination. So this decodes
 * the whole thing in memory, which is why memory_limit is raised below.
 *
 * Safe to run repeatedly: rows are upserted, and products the feed no longer
 * lists are deleted at the end.
 *
 * Run:  C:\xampp\php\php.exe tools\sync_catalog.php
 * Cron: every 2 hours is fine for stores with frequent price/stock changes.
 *
 * Target: PHP 7.4.
 */

ini_set('memory_limit', '1G');   // ~11.7 MB of JSON expands a long way as arrays
set_time_limit(0);

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/DigitalisApi.php';
require __DIR__ . '/../lib/Text.php';

const BATCH_SIZE = 250;

$syncLock = acquireSyncLock();
$startedAt = microtime(true);
$runStamp  = date('Y-m-d H:i:s');

$api = new DigitalisApi(config('digitalis_base_url'), config('digitalis_token'));
$pdo = db();
ensureProductActionColumns($pdo);

$runId = startRun($pdo);

try {
    echo "Syncing reference tables...\n";
    $brands = syncSimple($pdo, $api, '/brands', 'brands', ['id', 'name']);
    echo "  brands:         {$brands}\n";

    $supers = syncSimple($pdo, $api, '/supercategory', 'supercategories', ['id', 'name']);
    echo "  supercategories:{$supers}\n";

    $cats = syncSimple($pdo, $api, '/category', 'categories', ['id', 'super_category_id', 'name']);
    echo "  categories:     {$cats}\n";

    $subs = syncSimple($pdo, $api, '/category/subcategory', 'subcategories', ['id', 'category_id', 'name']);
    echo "  subcategories:  {$subs}\n";

    echo "\nDownloading products (expect ~12 MB)...\n";
    $products = $api->get('/products', ['page' => 1]);

    if (!is_array($products)) {
        throw new RuntimeException('Products endpoint did not return a list.');
    }

    $total = count($products);
    echo "  received {$total} products\n";

    if ($total < 100) {
        // A near-empty feed is far more likely to be an upstream glitch than a
        // genuine catalog wipe. Refuse rather than delete everything.
        throw new RuntimeException(
            "Only {$total} products returned — refusing to sync. "
            . 'This looks like an API problem, not an empty catalog.'
        );
    }

    // Brand names let customers search "Samsung televizor" and hit the product
    // even though the feed stores the brand only as an id.
    $brandNames = $pdo->query('SELECT id, name FROM brands')->fetchAll(PDO::FETCH_KEY_PAIR);

    echo "\nWriting products...\n";
    $written = writeProducts($pdo, $products, $brandNames, $runStamp);
    echo "  upserted {$written}\n";

    // Anything not touched by this run is no longer in the feed.
    $delete = $pdo->prepare('DELETE FROM products WHERE synced_at < ?');
    $delete->execute([$runStamp]);
    $removed = $delete->rowCount();
    echo "  removed {$removed} discontinued\n";

    finishRun($pdo, $runId, 'ok', $written, "removed {$removed}");

    $elapsed = round(microtime(true) - $startedAt, 1);
    echo "\nDone in {$elapsed}s.\n";
} catch (Throwable $e) {
    finishRun($pdo, $runId, 'failed', 0, $e->getMessage());
    fwrite(STDERR, "\nSYNC FAILED: {$e->getMessage()}\n");
    exit(1);
}

// ---------------------------------------------------------------------------

/**
 * Prevent overlapping cron runs. If a previous sync is still running, this
 * run exits successfully so cron does not spam failure emails.
 *
 * @return resource
 */
function acquireSyncLock()
{
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create data directory for sync lock.');
    }

    $handle = fopen($dir . '/sync_catalog.lock', 'c');
    if ($handle === false) {
        throw new RuntimeException('Cannot open sync lock file.');
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        echo "Another sync is already running; skipping.\n";
        exit(0);
    }

    return $handle;
}

/**
 * Add action/promotion columns to existing local databases.
 *
 * New installs get these from db/schema.sql; older local test databases need a
 * lightweight migration so sync can run without manual SQL.
 *
 * @param PDO $pdo
 * @return void
 */
function ensureProductActionColumns(PDO $pdo)
{
    $columns = [
        'is_action'    => 'TINYINT(1) NOT NULL DEFAULT 0',
        'action_price' => 'DECIMAL(10,2) NULL',
        'price_before' => 'DECIMAL(10,2) NULL',
        'discount_percent' => 'DECIMAL(5,2) NULL',
        'action_start' => 'VARCHAR(32) NULL',
        'action_end'   => 'VARCHAR(32) NULL',
        // Primary product image URL from the API feed. Older versions guessed
        // images from EAN; the feed is now the source of truth.
        'image_url' => 'VARCHAR(1024) NULL',
        // Raw product specifications from the API feed, stored as JSON so
        // answers can read structured facts before falling back to prose.
        'specifications_json' => 'MEDIUMTEXT NULL',
        // Per-storefront visibility, added to the API 2026-08-24. is_vp =
        // shown on the wholesale site (digitalis.ba), is_mp = shown on the
        // retail site (dstore.ba). Default 1 so an old row not yet touched
        // by a sync stays visible rather than silently disappearing.
        'is_vp' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'is_mp' => 'TINYINT(1) NOT NULL DEFAULT 1',
        // Real "Novo" badge flag, added to the API 2026-08-25 at our
        // request - confirmed live on digitalis.ba (132/10676 products
        // flagged). Default 0: an old row not yet touched by a sync should
        // not claim to be new.
        'new_product' => 'TINYINT(1) NOT NULL DEFAULT 0',
    ];

    $existing = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM products');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['Field'])) {
            $existing[(string) $row['Field']] = true;
        }
    }

    foreach ($columns as $column => $definition) {
        if (!isset($existing[$column])) {
            $pdo->exec("ALTER TABLE products ADD COLUMN `{$column}` {$definition}");
        }
    }

    try {
        $stmt = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'idx_action'");
        if ($stmt !== false && $stmt->fetch() === false) {
            $pdo->exec('ALTER TABLE products ADD KEY idx_action (is_action)');
        }
    } catch (Throwable $e) {
        // The column exists and search still works without the optional index.
        error_log('sync_catalog.php: could not add idx_action — ' . $e->getMessage());
    }
}

/**
 * The API marks action products and gives RRPrice_before, but currently leaves
 * RRPrice blank for action items. The official webshop page contains the live
 * action price, so the nightly sync enriches only action products from there.
 *
 * @param int $productId
 * @return array{price:float|null,price_before:float|null}
 */
function fetchWebActionPrices($productId)
{
    static $cache = [];

    $productId = (int) $productId;
    if (isset($cache[$productId])) {
        return $cache[$productId];
    }

    $empty = ['price' => null, 'price_before' => null];
    if ($productId <= 0) {
        return $empty;
    }

    $shopBase = rtrim((string) config_get('shop_base_url', 'https://www.digitalis.ba'), '/');
    $url = $shopBase . '/webshop/proizvod/' . $productId . '/p';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'DStoreChatCatalogSync/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml'],
    ]);

    $html   = curl_exec($ch);
    $errNo  = curl_errno($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errNo !== 0 || $status < 200 || $status >= 300 || !is_string($html) || $html === '') {
        $cache[$productId] = $empty;
        return $empty;
    }

    $price = null;
    $priceBefore = null;
    $id = preg_quote((string) $productId, '/');

    // Main product price block near the top of the page.
    if (preg_match(
        '/<div[^>]*class=["\']product-info2["\'][^>]*data-id=["\']?' . $id . '["\']?[^>]*>.*?<div[^>]*class=["\']price["\'][^>]*>\s*<span>\s*([^<]+)\s*<\/span>/isu',
        $html,
        $m
    )) {
        $price = parseWebMoney($m[1]);
    }

    // Product pages also include listing cards. For action cards the same
    // product id carries both price-old and price, which lets us verify/update
    // the old price too.
    if (preg_match('/<article[^>]*data-id=["\']?' . $id . '["\']?[^>]*>(.*?)<\/article>/isu', $html, $m)) {
        $article = $m[1];

        if (preg_match('/<div[^>]*class=["\']price-old["\'][^>]*>(.*?)<\/div>/isu', $article, $old)) {
            $parsed = parseWebMoney($old[1]);
            if ($parsed !== null) {
                $priceBefore = $parsed;
            }
        }
        if ($price === null && preg_match('/<div[^>]*class=["\']price["\'][^>]*>(.*?)<\/div>/isu', $article, $cur)) {
            $parsed = parseWebMoney($cur[1]);
            if ($parsed !== null) {
                $price = $parsed;
            }
        }
    }

    $cache[$productId] = ['price' => $price, 'price_before' => $priceBefore];
    return $cache[$productId];
}

/**
 * Parse webshop money strings such as "139,00 KM" or "1.299,90 KM".
 *
 * Text::parseNumber is for API values like "1,299.90"; the webshop uses the
 * opposite separators, so it needs its own parser.
 *
 * @param string $value
 * @return float|null
 */
function parseWebMoney($value)
{
    $clean = html_entity_decode(strip_tags((string) $value), ENT_QUOTES, 'UTF-8');
    $clean = preg_replace('/[^\d,.]/u', '', $clean);

    if ($clean === '') {
        return null;
    }

    $lastComma = strrpos($clean, ',');
    $lastDot   = strrpos($clean, '.');

    if ($lastComma !== false && $lastDot !== false) {
        if ($lastComma > $lastDot) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } else {
            $clean = str_replace(',', '', $clean);
        }
    } elseif ($lastComma !== false) {
        $clean = str_replace(',', '.', $clean);
    }

    return is_numeric($clean) ? (float) $clean : null;
}

/**
 * Read the current product price from the API row.
 *
 * Today the feed uses RRPrice for both normal and action prices. The extra key
 * names make the sync ready if the API later adds a clearer action_price field.
 *
 * @param array $product
 * @return float|null
 */
function apiProductPrice(array $product)
{
    foreach (['action_price', 'ActionPrice', 'RRPrice_action', 'RRPrice'] as $key) {
        if (isset($product[$key]) && trim((string) $product[$key]) !== '') {
            return Text::parseNumber($product[$key]);
        }
    }

    return null;
}

/**
 * Protect the catalog from action rows where RRPrice is not a KM amount.
 *
 * After the API update, most action rows correctly send RRPrice as the action
 * price, e.g. 139.00 with RRPrice_before 199.90. A handful still send values
 * like 0.70 or 0.95 for expensive items, which are clearly coefficients/rates
 * rather than customer-facing KM prices. Do not store those as "0,70 KM".
 *
 * @param float|null $price
 * @param float|null $priceBefore
 * @param bool       $isAction
 * @return float|null
 */
function validatedApiPrice($price, $priceBefore, $isAction)
{
    if ($price === null || $price <= 0) {
        return null;
    }

    if ($isAction && $priceBefore !== null && $priceBefore > 10 && $price <= 1) {
        return null;
    }

    return $price;
}

/**
 * Upsert a small reference table straight from the API.
 *
 * @param PDO          $pdo
 * @param DigitalisApi $api
 * @param string       $path
 * @param string       $table
 * @param string[]     $columns Column names, which match the API's field names.
 * @return int Rows processed.
 */
function syncSimple(PDO $pdo, DigitalisApi $api, $path, $table, array $columns)
{
    $rows = $api->get($path);
    if (!is_array($rows)) {
        throw new RuntimeException("{$path} did not return a list.");
    }

    $normalized = [];
    foreach ($rows as $row) {
        if (!isset($row['id'])) {
            continue;
        }
        $values = [];
        foreach ($columns as $column) {
            $values[] = isset($row[$column]) ? $row[$column] : null;
        }
        $normalized[] = $values;
    }

    upsertBatch($pdo, $table, $columns, $normalized);
    return count($normalized);
}

/**
 * Map feed products onto our schema and upsert them.
 *
 * @param PDO   $pdo
 * @param array $products
 * @param array $brandNames id => name
 * @param string $runStamp
 * @return int
 */
function writeProducts(PDO $pdo, array $products, array $brandNames, $runStamp)
{
    $columns = [
        'id', 'ean', 'model', 'name', 'description', 'image_url', 'specifications_json',
        'brand_id', 'category_id', 'subcategory_id',
        'price', 'is_action', 'action_price', 'price_before', 'discount_percent',
        'action_start', 'action_end',
        'stock', 'warranty_months', 'weight_kg',
        'is_vp', 'is_mp', 'new_product',
        'search_text', 'name_text', 'head_word', 'synced_at',
    ];

    $rows  = [];
    $count = 0;

    foreach ($products as $p) {
        if (!isset($p['ID'])) {
            continue;
        }

        $brandId   = isset($p['brand_id']) ? (int) $p['brand_id'] : null;
        $brandName = ($brandId !== null && isset($brandNames[$brandId])) ? $brandNames[$brandId] : '';

        $name        = isset($p['name']) ? (string) $p['name'] : '';
        $model       = isset($p['Model']) ? (string) $p['Model'] : '';
        $description = isset($p['description']) ? (string) $p['description'] : '';
        $imageUrl    = apiProductImageUrl($p);
        $specifications = apiProductSpecifications($p);
        $specificationsJson = $specifications === []
            ? null
            : json_encode($specifications, JSON_UNESCAPED_UNICODE);
        $specificationsText = specificationsSearchText($specifications);
        $priceBefore = isset($p['RRPrice_before']) && trim((string) $p['RRPrice_before']) !== ''
            ? Text::parseNumber($p['RRPrice_before'])
            : null;
        $actionRaw   = isset($p['action']) ? trim((string) $p['action']) : '';
        $isAction    = ($actionRaw !== '' && $actionRaw !== '0') || ($priceBefore !== null && $priceBefore > 0);
        $feedPrice   = apiProductPrice($p);
        $price       = validatedApiPrice($feedPrice, $priceBefore, $isAction);
        $actionPrice = $isAction ? $price : null;
        $discountPercent = isset($p['discount_percent']) && trim((string) $p['discount_percent']) !== ''
            ? Text::parseNumber($p['discount_percent'])
            : null;

        if ($isAction && $actionPrice === null && config_get('enrich_action_prices', true)) {
            $pagePrices = fetchWebActionPrices((int) $p['ID']);
            if ($pagePrices['price'] !== null && $pagePrices['price'] > 0) {
                $actionPrice = $pagePrices['price'];
                $price = $actionPrice;
            }
            if ($pagePrices['price_before'] !== null && $pagePrices['price_before'] > 0) {
                $priceBefore = $pagePrices['price_before'];
            }
        }

        if (($discountPercent === null || $discountPercent <= 0)
            && $isAction
            && $actionPrice !== null
            && $priceBefore !== null
            && $priceBefore > $actionPrice
        ) {
            $discountPercent = round((($priceBefore - $actionPrice) / $priceBefore) * 100, 2);
        }

        $nameText = Text::normalize($name . ' ' . $model . ' ' . $brandName);

        $rows[] = [
            (int) $p['ID'],
            isset($p['EAN']) ? (string) $p['EAN'] : '',
            $model,
            $name,
            $description,
            $imageUrl,
            $specificationsJson,
            $brandId,
            isset($p['category_id']) ? (int) $p['category_id'] : null,
            isset($p['subcategory_id']) ? (int) $p['subcategory_id'] : null,
            // RRPrice, RRPrice_before and stock arrive as strings, stock with
            // comma thousands separators. Text::parseNumber handles them.
            // If RRPrice is blank, keep price null; do not invent an action
            // price from RRPrice_before. For action products, price may be
            // enriched from the official webshop product page above.
            $price,
            $isAction ? 1 : 0,
            $actionPrice,
            $priceBefore,
            $discountPercent,
            isset($p['date_start']) && trim((string) $p['date_start']) !== '' ? (string) $p['date_start'] : null,
            isset($p['date_end']) && trim((string) $p['date_end']) !== '' ? (string) $p['date_end'] : null,
            isset($p['stock']) ? Text::parseNumber($p['stock']) : 0.0,
            isset($p['warranty']) ? (int) $p['warranty'] : null,
            isset($p['weight_netto']) ? Text::parseWeightKg($p['weight_netto']) : null,
            // Per-storefront visibility (added to the API 2026-08-24). Default
            // to visible (1) when the feed omits the field, matching the
            // column default, so an older/partial feed response never hides
            // a product that should be shown.
            isset($p['is_vp']) ? (int) $p['is_vp'] : 1,
            isset($p['is_mp']) ? (int) $p['is_mp'] : 1,
            // "Novo" badge flag (added to the API 2026-08-25). Default 0
            // when the feed omits it - unlike is_vp/is_mp, hiding is the
            // safe default here, not showing.
            isset($p['new_product']) ? (int) $p['new_product'] : 0,
            Text::normalize($name . ' ' . $model . ' ' . $brandName . ' ' . $description . ' ' . $specificationsText),
            // Name/model/brand only, so ranking can tell "a laptop" apart
            // from "a backpack for a laptop".
            $nameText,
            // First word only — "Laptop 15.6" -> laptop, "Miš optički" -> mis.
            mb_substr(strtok($nameText, ' '), 0, 64),
            $runStamp,
        ];

        if (count($rows) >= BATCH_SIZE) {
            upsertBatch($pdo, 'products', $columns, $rows);
            $count += count($rows);
            $rows = [];
            echo "\r  {$count}...";
        }
    }

    if ($rows !== []) {
        upsertBatch($pdo, 'products', $columns, $rows);
        $count += count($rows);
    }

    echo "\r";
    return $count;
}

/**
 * Extract the primary product image URL from the API feed.
 *
 * The feed has changed shape before, so accept both a direct string and nested
 * objects/lists such as images[0].url, photo.src, thumbnail.path, etc.
 *
 * @param array $product
 * @return string|null
 */
function apiProductImageUrl(array $product)
{
    $keys = [
        'image_url', 'imageUrl', 'main_image_url', 'mainImageUrl',
        'thumbnail_url', 'thumbnailUrl', 'picture_url', 'pictureUrl',
        'photo_url', 'photoUrl', 'slika_url', 'slikaUrl',
        'image', 'images', 'main_image', 'mainImage',
        'thumbnail', 'thumb', 'picture', 'pictures', 'photo', 'photos',
        'slika', 'slike',
    ];

    foreach ($keys as $key) {
        if (array_key_exists($key, $product)) {
            $url = firstImageUrlCandidate($product[$key]);
            if ($url !== null) {
                return $url;
            }
        }
    }

    return null;
}

/**
 * @param array $product
 * @return array<int,array{name:string,value:string,type:string}>
 */
function apiProductSpecifications(array $product)
{
    if (!isset($product['specifications']) || !is_array($product['specifications'])) {
        return [];
    }

    $out = [];
    foreach ($product['specifications'] as $spec) {
        if (!is_array($spec)) {
            continue;
        }

        $name = firstStringValue($spec, ['name', 'label', 'title', 'spec_name', 'specName']);
        $value = firstStringValue($spec, ['value', 'val', 'text', 'spec_value', 'specValue']);
        if ($name === '' || $value === '') {
            continue;
        }

        $out[] = [
            'name'  => $name,
            'value' => $value,
            'type'  => firstStringValue($spec, ['spec_type', 'specType', 'type']),
        ];
    }

    return $out;
}

/**
 * @param array<int,array{name:string,value:string,type:string}> $specifications
 * @return string
 */
function specificationsSearchText(array $specifications)
{
    $parts = [];
    foreach ($specifications as $spec) {
        $parts[] = $spec['name'];
        $parts[] = $spec['value'];
    }

    return implode(' ', $parts);
}

/**
 * @param array  $row
 * @param string[] $keys
 * @return string
 */
function firstStringValue(array $row, array $keys)
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && !is_array($row[$key])) {
            $value = trim((string) $row[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

/**
 * @param mixed $value
 * @return string|null
 */
function firstImageUrlCandidate($value)
{
    if (is_string($value) || is_numeric($value)) {
        return normalizeImageUrl((string) $value);
    }

    if (!is_array($value)) {
        return null;
    }

    foreach (['small', 'thumbnail', 'thumb', 'url', 'src', 'path', 'link', 'href'] as $key) {
        if (array_key_exists($key, $value)) {
            $url = firstImageUrlCandidate($value[$key]);
            if ($url !== null) {
                return $url;
            }
        }
    }

    foreach ($value as $child) {
        $url = firstImageUrlCandidate($child);
        if ($url !== null) {
            return $url;
        }
    }

    return null;
}

/**
 * @param string $url
 * @return string|null
 */
function normalizeImageUrl($url)
{
    $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
    if ($url === '' || stripos($url, 'data:') === 0) {
        return null;
    }

    if (preg_match('#^https?://#i', $url) === 1) {
        return $url;
    }

    if (strpos($url, '//') === 0) {
        return 'https:' . $url;
    }

    if (strpos($url, '/') === 0) {
        return rtrim(syncImageBaseUrl(), '/') . $url;
    }

    if (preg_match('#\.(?:jpe?g|png|webp|gif)(?:\?.*)?$#i', $url) === 1) {
        return rtrim(syncImageBaseUrl(), '/') . '/' . ltrim($url, '/');
    }

    return null;
}

/**
 * @return string
 */
function syncImageBaseUrl()
{
    $base = (string) config_get('image_base_url', '');
    if ($base !== '') {
        return $base;
    }

    $apiBase = rtrim((string) config_get('digitalis_base_url', ''), '/');
    if ($apiBase !== '') {
        $parts = parse_url($apiBase);
        if (isset($parts['scheme'], $parts['host'])) {
            $base = $parts['scheme'] . '://' . $parts['host'];
            if (isset($parts['port'])) {
                $base .= ':' . $parts['port'];
            }
            return $base;
        }
    }

    return (string) config_get('shop_base_url', 'https://www.digitalis.ba');
}

/**
 * Multi-row INSERT ... ON DUPLICATE KEY UPDATE, wrapped in a transaction.
 *
 * Batches are sized by BYTES, not by row count. Product descriptions vary from
 * a few characters to ten kilobytes, so a fixed 250 rows can produce anything
 * between 20 KB and 2.5 MB — and anything over MySQL's max_allowed_packet
 * (1 MB by default) kills the connection with "MySQL server has gone away",
 * which reads like a network fault rather than an oversized query.
 *
 * @param PDO      $pdo
 * @param string   $table
 * @param string[] $columns
 * @param array[]  $rows
 * @return void
 */
function upsertBatch(PDO $pdo, $table, array $columns, array $rows)
{
    if ($rows === []) {
        return;
    }

    $columnList  = '`' . implode('`,`', $columns) . '`';
    $placeholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';

    $updates = [];
    foreach ($columns as $column) {
        if ($column === 'id') {
            continue;
        }
        $updates[] = "`{$column}`=VALUES(`{$column}`)";
    }
    $updateSql = ' ON DUPLICATE KEY UPDATE ' . implode(',', $updates);

    $budget = packetBudget($pdo);

    $pdo->beginTransaction();
    try {
        $chunk = [];
        $bytes = 0;

        foreach ($rows as $row) {
            $rowBytes = 0;
            foreach ($row as $value) {
                $rowBytes += strlen((string) $value) + 3;   // + quoting overhead
            }

            // Flush before adding a row that would push us over the limit.
            if ($chunk !== [] && ($bytes + $rowBytes) > $budget) {
                flushChunk($pdo, $table, $columnList, $placeholder, $updateSql, $chunk);
                $chunk = [];
                $bytes = 0;
            }

            $chunk[] = $row;
            $bytes  += $rowBytes;

            // A single row larger than the budget cannot be split; send it alone
            // and let the server complain if it truly cannot take it.
            if (count($chunk) >= BATCH_SIZE) {
                flushChunk($pdo, $table, $columnList, $placeholder, $updateSql, $chunk);
                $chunk = [];
                $bytes = 0;
            }
        }

        if ($chunk !== []) {
            flushChunk($pdo, $table, $columnList, $placeholder, $updateSql, $chunk);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Send one chunk.
 *
 * @param PDO     $pdo
 * @param string  $table
 * @param string  $columnList
 * @param string  $placeholder
 * @param string  $updateSql
 * @param array[] $chunk
 * @return void
 */
function flushChunk(PDO $pdo, $table, $columnList, $placeholder, $updateSql, array $chunk)
{
    $sql = "INSERT INTO `{$table}` ({$columnList}) VALUES "
         . implode(',', array_fill(0, count($chunk), $placeholder))
         . $updateSql;

    $flat = [];
    foreach ($chunk as $row) {
        foreach ($row as $value) {
            $flat[] = $value;
        }
    }

    $pdo->prepare($sql)->execute($flat);
}

/**
 * How many bytes we allow per INSERT.
 *
 * Half of max_allowed_packet, so the statement text plus protocol overhead
 * still fits comfortably.
 *
 * @param PDO $pdo
 * @return int
 */
function packetBudget(PDO $pdo)
{
    static $budget = null;

    if ($budget === null) {
        $budget = 400000;   // conservative default if the query fails
        try {
            $row = $pdo->query("SHOW VARIABLES LIKE 'max_allowed_packet'")->fetch();
            if ($row !== false && isset($row['Value'])) {
                $budget = max(100000, (int) ((int) $row['Value'] * 0.5));
            }
        } catch (Throwable $e) {
            // keep the default
        }
    }

    return $budget;
}
/**
 * @param PDO $pdo
 * @return int
 */
function startRun(PDO $pdo)
{
    $pdo->prepare('INSERT INTO sync_runs (status) VALUES (?)')->execute(['running']);
    return (int) $pdo->lastInsertId();
}

/**
 * @param PDO    $pdo
 * @param int    $runId
 * @param string $status
 * @param int    $seen
 * @param string $note
 * @return void
 */
function finishRun(PDO $pdo, $runId, $status, $seen, $note)
{
    $pdo->prepare(
        'UPDATE sync_runs SET finished_at = NOW(), status = ?, products_seen = ?, note = ? WHERE id = ?'
    )->execute([$status, $seen, $note, $runId]);
}
