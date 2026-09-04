<?php
/**
 * Product search over the synced catalog.
 *
 * Two-stage on purpose. MySQL's FULLTEXT index ignores tokens shorter than
 * innodb_ft_min_token_size (3 by default), so a search for "TV" or "A4" finds
 * nothing through MATCH. When fulltext returns nothing we fall back to LIKE,
 * which is slower but correct — and only runs on the queries fulltext cannot
 * serve.
 *
 * Target: PHP 7.4.
 */
class ProductSearch
{
    /** @var PDO */
    private $pdo;

    /** @var array<string,string> Resolved token cache, per request. */
    private $tokenCache = [];

    /** @var array<string,int> Prefix match-count cache, per request. */
    private $countCache = [];

    /** @var array|null Category/subcategory lookup cache, per request. */
    private $bucketCache = null;

    /** @var array|null Brand name lookup cache, per request. */
    private $brandCache = null;

    /** @var bool Whether the local products table has action/promotion columns. */
    private $actionColumnsAvailable = false;

    /** @var bool Whether the local products table has is_vp/is_mp columns. */
    private $visibilityColumnsAvailable = false;

    /** @var bool Whether the local products table has the new_product column. */
    private $newProductColumnAvailable = false;

    /** @param PDO $pdo */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->actionColumnsAvailable = $this->ensureActionColumns();
        $this->visibilityColumnsAvailable = $this->ensureVisibilityColumns();
        $this->newProductColumnAvailable = $this->ensureNewProductColumn();
    }

    /**
     * @param string $query
     * @param array  $options limit, in_stock_only, supercategory_id, category_id, subcategory_id, max_price, min_price, target_price, sort, action_only, new_only, exclude_ids
     * @return array[] Product rows.
     */
    public function search($query, array $options = [])
    {
        $limit        = isset($options['limit']) ? max(1, min(50, (int) $options['limit'])) : 8;
        $inStockOnly  = !empty($options['in_stock_only']);
        $supercategoryId = isset($options['supercategory_id']) ? (int) $options['supercategory_id'] : null;
        $categoryId   = isset($options['category_id']) ? (int) $options['category_id'] : null;
        $subcategoryId = isset($options['subcategory_id']) ? (int) $options['subcategory_id'] : null;
        $minPrice     = isset($options['min_price']) ? (float) $options['min_price'] : null;
        $maxPrice     = isset($options['max_price']) ? (float) $options['max_price'] : null;
        $targetPrice  = isset($options['target_price']) ? (float) $options['target_price'] : null;
        $excludeIds   = isset($options['exclude_ids']) && is_array($options['exclude_ids']) ? $options['exclude_ids'] : [];
        $sort         = isset($options['sort']) ? strtolower(trim((string) $options['sort'])) : null;
        $actionOnly   = !empty($options['action_only']);
        $newOnly      = !empty($options['new_only']);
        // Baseline visibility (always applied): is_mp on both dstore.ba and
        // the public/logged-out view of digitalis.ba - the two sites show
        // the same "public" catalog by default. wholesale_verified widens
        // this to also include catalog_wholesale_column (is_vp on
        // digitalis.ba) - the extra articles a logged-in wholesale account
        // sees. wholesale_verified is always false until the login-aware API
        // (still pending, see docs/deployment.md) tells us the visitor is
        // actually logged in - never trust a client-supplied flag for this.
        $visibilityColumn = (string) config_get('catalog_visibility_column', '');
        if (!in_array($visibilityColumn, ['is_vp', 'is_mp'], true)) {
            $visibilityColumn = null;
        }
        $wholesaleVerified = !empty($options['wholesale_verified']);
        $wholesaleColumn = (string) config_get('catalog_wholesale_column', '');
        if (!in_array($wholesaleColumn, ['is_vp', 'is_mp'], true)) {
            $wholesaleColumn = null;
        }
        if (!in_array($sort, ['price_asc', 'price_desc', 'discount_desc'], true)) {
            $sort = null;
        }

        $query = Text::stripCatalogMetaPhrases($query);

        $sortIntent = Text::extractSortIntent($query);
        $query      = $sortIntent['query'];
        if ($sort === null && $sortIntent['sort'] !== null) {
            $sort = $sortIntent['sort'];
        }
        if ($sort === 'discount_desc') {
            $actionOnly = true;
        }

        $actionIntent = $this->extractActionIntent($query);
        $query        = $actionIntent['query'];
        if ($actionIntent['action_only']) {
            $actionOnly = true;
        }

        // A caller may pass the customer's whole sentence. Strip any spending
        // limit out of it and use it as a filter instead of as search words.
        $budget = Text::extractBudget($query);
        $query  = $budget['query'];
        if ($minPrice === null && $budget['min_price'] !== null) {
            $minPrice = $budget['min_price'];
        }
        if ($maxPrice === null && $budget['max_price'] !== null) {
            $maxPrice = $budget['max_price'];
        }
        if ($targetPrice === null && isset($budget['target_price']) && $budget['target_price'] !== null) {
            $targetPrice = $budget['target_price'];
        }

        // A brand mentioned in the sentence ("gorenje veš mašine do 700 KM")
        // becomes a real brand_id filter instead of relying on the brand name
        // happening to also appear in the product's indexed text - which it
        // usually does not, and which silently drops under a budget filter
        // that leaves that brand with nothing to show.
        $brandId = isset($options['brand_id']) ? (int) $options['brand_id'] : null;
        $brandOnlyQuery = false;
        if ($brandId === null) {
            $brandMatch = $this->extractBrand($query);
            if ($brandMatch !== null) {
                // Some brand names are also literally part of a real
                // category/subcategory name ("Amiko Smart Home", "Michelin
                // perači/čistaći/pribor"), or the brand word is itself a
                // recognized bucket alias in its own right ("xbox", "ps5",
                // "nintendo switch" -> the "Igraće konzole" bucket). Either
                // way, stripping it would lose the bucket match entirely -
                // found 2026-08-26 that stripping "xbox"/"nintendo" left an
                // empty or wrong leftover query and lost the console match,
                // even though the ORIGINAL text (brand included) already
                // resolved it correctly.
                //
                // Only skip the strip when it would actually cost the
                // match, though: "samsung telefon" also resolves on the
                // original text (intentBucketForQuery finds "telefon"
                // embedded in it), but the STRIPPED text "telefon" resolves
                // to that exact same bucket just as well - Samsung there is
                // a genuine, separate brand_id filter on top of a
                // perfectly-identifiable product type, not load-bearing for
                // the bucket match itself, and losing it would show phones
                // of any brand instead of just Samsung. Compare the bucket
                // peek WITH and WITHOUT the brand word; only keep the brand
                // word in when removing it changes (or loses) the answer.
                $bucketWithBrand = $this->bucketForQuery($query, $supercategoryId);
                if ($bucketWithBrand === null) {
                    $bucketWithBrand = $this->intentBucketForQuery($query);
                }
                $bucketWithoutBrand = $this->bucketForQuery($brandMatch['query'], $supercategoryId);
                if ($bucketWithoutBrand === null) {
                    $bucketWithoutBrand = $this->intentBucketForQuery($brandMatch['query']);
                }
                $sameBucketEitherWay = $bucketWithBrand !== null && $bucketWithoutBrand !== null
                    && $bucketWithBrand['type'] === $bucketWithoutBrand['type']
                    && (int) $bucketWithBrand['id'] === (int) $bucketWithoutBrand['id'];

                if ($bucketWithBrand === null || $sameBucketEitherWay) {
                    $brandId = $brandMatch['id'];
                    $query   = $brandMatch['query'];
                    $brandOnlyQuery = Text::meaningfulTokens($query) === [];
                }
            }
        } elseif (Text::meaningfulTokens($query) === []) {
            $brandOnlyQuery = true;
        }

        // Any real filter alone makes a BLANK query ("Roborock", "any deals",
        // "new arrivals") a complete, meaningful search. This must be
        // computed after brand extraction too: bare brand queries strip down
        // to no product words by design, and should browse that brand instead
        // of returning an empty result.
        $hasFilterOnlyQuery = $actionOnly || $newOnly || $brandId !== null || $sort !== null;

        // Washing machine spin speed ("1400 obrtaja", "preko 1200 obrtaja").
        $spinMin = $spinMax = $spinTarget = null;
        $spin = $this->extractSpinSpeedRpm($query);
        if ($spin !== null) {
            $spinMin    = $spin['min'];
            $spinMax    = $spin['max'];
            $spinTarget = $spin['target'];
            $query      = $spin['query'];
        }

        // If removing the price leaves only conversational filler ("a koje
        // imate", "ima li neke"), do not search those words. Conversation
        // context should fill the product type; without context, returning no
        // products is safer than showing random matches.
        $meaningful = Text::meaningfulTokens($query);
        if ($meaningful === [] && !$hasFilterOnlyQuery) {
            return [];
        }

        // "Radno vrijeme" (working/opening hours) is a real store-logistics
        // phrase, never a product search - but "radno"/"radni"/"radna" all
        // stem to "radn", the same stem as the "Radni sto..." desk product
        // line, and a bare "radno"/"radni" IS a legitimate way to ask for
        // that desk (kept working on purpose). The AI is instructed not to
        // call this tool for hours questions at all (see system_prompt.txt),
        // but that instruction is not 100% reliable - found 2026-08-27 that
        // "radno vrijeme" (and "koje je vrijeme u Digitalisu", which the
        // model itself paraphrased into that search) still got searched and
        // returned that desk as an irrelevant result tacked onto an
        // otherwise-correct "call us" answer. Filtering the search result
        // itself (not just the prompt instruction) closes this regardless of
        // why the search happened. Scoped narrowly to "radn word(s) +
        // vrijeme word" specifically, so it does not touch a bare "radno"/
        // "radni sto" query.
        // "vrijeme" (ijekavica) vs "vreme" (ekavica) only differ in the
        // nominative - every other case (vremena, vremenu, vremenom) already
        // starts "vremen-" regardless of dialect, and ekavica's nominative
        // "vreme" itself already starts with "vrem-" too. So two
        // alternatives cover every case/dialect form: "vrijem*" for the one
        // ijekavica form that does not start "vrem-", "vrem*" for all the
        // rest. Found 2026-08-27: "radnom vremenu" slipped past an earlier,
        // narrower version of this check that only matched "vrijem*".
        if (preg_match('/\bradn\w*\s+(?:vrijem\w*|vrem\w*)\b/u', Text::normalize($query))) {
            return [];
        }

        $intentQuery       = $query;
        $targetCableLength = $this->extractCableLengthMeters($intentQuery);
        $tokens            = ($meaningful === [] && $hasFilterOnlyQuery) ? [] : Text::tokens($query);
        if ($tokens === [] && !$hasFilterOnlyQuery) {
            return [];
        }

        $bucket = null;
        $bucketBrowseOnly = false;
        if ($categoryId === null && $subcategoryId === null) {
            $bucket = $this->bucketForQuery($query, $supercategoryId);
            if ($bucket === null) {
                $bucket = $this->intentBucketForQuery($query);
                if ($bucket !== null && !$this->bucketEntryMatchesScope($bucket, $supercategoryId)) {
                    $bucket = null;
                }
            }
            if ($bucket !== null) {
                if ($bucket['type'] === 'supercategory') {
                    $supercategoryId = (int) $bucket['id'];
                } elseif ($bucket['type'] === 'subcategory') {
                    $subcategoryId = (int) $bucket['id'];
                } else {
                    $categoryId = (int) $bucket['id'];
                }

                $bucketQuery = $this->queryForBucketSearch($query, $bucket);
                if ($this->isExactBucketNameQuery($query, $bucket) || Text::meaningfulTokens($bucketQuery) === []) {
                    $tokens = [];
                    $bucketBrowseOnly = true;
                } else {
                    $query  = $bucketQuery;
                    $tokens = Text::tokens($query);
                }
            }
        }

        $productPreference = $this->productPreference($tokens);
        if ($productPreference === null) {
            $productPreference = $this->productPreferenceForQuery($intentQuery, $bucket);
        }
        if ($productPreference === null && $bucket !== null) {
            $productPreference = $this->preferenceForBucket($bucket);
        }

        if ($productPreference === 'pc_case') {
            // Product names only ever say "kućište za PC", never "računar"
            // or "kompjuter" - requiring the customer's own qualifier word
            // as a literal match would exclude the real product entirely
            // and leave only accessories that happen to mention it in their
            // description.
            $tokens = Text::tokens(preg_replace('/\b(?:racunar\w*|kompjuter\w*|\bpc\b)\b/u', ' ', Text::normalize($intentQuery)));
        }

        $filters = $this->buildFilters($inStockOnly, $supercategoryId, $categoryId, $subcategoryId, $minPrice, $maxPrice, $actionOnly, $brandId, $excludeIds, $visibilityColumn, $wholesaleVerified, $wholesaleColumn, $newOnly);

        // A single bare word with no resolved bucket ("ploce", "napa" before
        // it had an alias) is the highest-risk shape for matching unrelated
        // products that merely share a letter sequence ("ploce" -> LEGO
        // "Ploče za cestu"). Fetch a wider pool so we can tell whether one
        // real category clearly dominates before deciding what to show.
        $isBareSingleWordQuery = $bucket === null && count($tokens) === 1;
        $hasSpinConstraint = $spinMin !== null || $spinMax !== null || $spinTarget !== null;

        $searchLimit = ($targetPrice !== null || $targetCableLength !== null || $productPreference !== null || $sort !== null || $isBareSingleWordQuery || $hasSpinConstraint || $brandOnlyQuery)
            ? max($limit * 20, 100)
            : $limit;

        $results = [];
        if ($tokens === [] && $hasFilterOnlyQuery) {
            $results = $this->browseBucket($filters, $searchLimit, $sort);
        } elseif ($tokens !== []) {
            if ($this->looksLikeModelCodeQuery($tokens)) {
                $results = $this->modelCodeSearch($tokens, $filters, $searchLimit);
            }
            if ($results === []) {
                $results = $this->fulltextSearch($tokens, $filters, $searchLimit, $actionOnly || $brandId !== null);
            }
            if ($results === []) {
                $results = $this->likeSearch($tokens, $filters, $searchLimit);
                if (count($tokens) === 1 && !$this->resultsContainNameToken($results, $tokens[0])) {
                    $results = [];
                }
            }
            if ($results === [] && count($tokens) >= 3) {
                $results = $this->descriptivePhraseSearch($tokens, $filters, $searchLimit);
            }
        }

        if ($bucket !== null && $bucketBrowseOnly && count($results) < $limit) {
            $results = $this->browseBucket($filters, $searchLimit, $sort);

            // browseBucket() does no name-level filtering at all - it trusts
            // the category/subcategory assignment completely, which also
            // holds real accessories FOR the main product (a washing
            // machine pedestal, anti-vibration feet live in the same
            // subcategory as actual washing machines). Found 2026-08-25:
            // "veš mašine do 300 KM" (no real washer that cheap) fell
            // through to this browse and returned those accessories
            // instead, once the price filter eliminated every actual
            // machine. Keep only rows whose OWN head_word (first word of
            // their name - "masina" for a real washing machine, vs
            // "antivibracijske"/"postolje" for an accessory naming the
            // machine only in passing) matches the bucket's own name - the
            // same "is the thing itself, not just related to it" signal
            // fulltextSearch already applies via hasHeadMatch().
            //
            // Note: shape() (called by every run(), including the one
            // inside browseBucket() above) only exposes head_word under the
            // internal '_head_word' key - the raw 'head_word' column is
            // never in the shaped row, so checking isset($row['head_word'])
            // here was always false and this filter silently did nothing.
            // Found 2026-08-26 when a plain "laptop" browse came back empty
            // despite 48 real laptops in stock.
            //
            // Only apply this once that isset() bug is fixed did a second
            // problem surface: requiring the bucket's own first word as a
            // literal name prefix is wrong for most categories. "Ethernet"
            // (switches, adapters, cables), "E-Tekućine" (liquids sold under
            // their own brand names), "Foto okviri" (photo frames) hold
            // products whose names never start with the category/subcategory
            // word itself - it's only a coincidence that "Mašina za pranje
            // veša" and "Laptopi" happen to share a root with their real
            // product names. Applying this everywhere turned a huge swath of
            // plain category browses into false "nothing found" (caught by
            // tools/audit_search_quality.php). Scope it to what actually
            // caused the original bug: a max-price ceiling crowding out the
            // real (usually pricier) product and leaving only its cheap
            // accessories. Without a price ceiling, trust the
            // category/subcategory assignment as before - EXCEPT for a
            // small curated list of buckets already individually verified
            // this session (bucketAliases()/queryForBucketSearch() entries
            // for these exact names) to genuinely have every real product
            // start with the bucket's own first word - for those, the check
            // is safe unconditionally. Found 2026-08-26 on zed.hr: that
            // deployment's "Mašina za pranje veša" bucket currently has ZERO
            // real washing machines in stock (only stands/anti-vibration
            // feet), so a plain "perilica rublja" (no price at all) fell
            // through to this browse and returned an accessory - the
            // price-only gate never even ran.
            //
            // "Verified" means checked against an actual head_word
            // distribution query, not eyeballed from a few sample names -
            // "router" looked safe from a couple of examples ("Router
            // AC1200...") but is actually dominated by products named
            // "Wireless N router..." (head_word "wireless", 25 of 36
            // products) with only 3 literally starting "Router" - the
            // first attempt at this list included it and "printeri i
            // skeneri" from eyeballing alone, which silently broke bare
            // "router"/"skener" browsing on Digitalis (caught by
            // tools/audit_search_quality.php) before this list was
            // corrected against real data.
            $verifiedHeadWordBuckets = [
                'masina za pranje vesa', 'igrace konzole', 'ugradbena pecnica',
                'mikrovalne pecnice', 'desktop aio racunari', 'laptopi',
                'monitori',
            ];
            $bucketNameKey = Text::normalize((string) $bucket['name']);
            $expectedHead = ($maxPrice !== null && $maxPrice > 0) || in_array($bucketNameKey, $verifiedHeadWordBuckets, true)
                ? $this->resolveToken((string) strtok($bucketNameKey, ' '))
                : '';
            if ($expectedHead !== '') {
                $anchored = [];
                foreach ($results as $row) {
                    if (isset($row['_head_word']) && strpos((string) $row['_head_word'], $expectedHead) === 0) {
                        $anchored[] = $row;
                    }
                }
                // Deliberately no "if nothing survives, keep the unfiltered
                // browse" fallback: that would silently reintroduce the
                // exact bug this fixes. If a price/other filter eliminated
                // every real head-matching item and only accessories are
                // left, reporting nothing found is the honest answer, not
                // showing an accessory as if it were the product itself.
                $results = $anchored;
            }
        }

        // A brand + broad bucket can be too narrow when the exact bucket has
        // no stock for that brand, but a more specific sibling product type
        // does. Example: "Xiaomi printeri" can miss "mobilni printer" if the
        // resolved "Printeri i skeneri" bucket has no Xiaomi rows. Before we
        // answer "nothing", search the same brand with the customer's product
        // words across the wider catalog so sibling/specialized subcategories
        // get a chance to surface.
        if ($results === [] && $brandId !== null && $bucket !== null) {
            $wideFilters = $this->buildFilters(
                $inStockOnly,
                null,
                null,
                null,
                $minPrice,
                $maxPrice,
                $actionOnly,
                $brandId,
                $excludeIds,
                $visibilityColumn,
                $wholesaleVerified,
                $wholesaleColumn,
                $newOnly
            );
            $wideTokens = Text::tokens($intentQuery);
            $wideResults = [];

            if ($wideTokens === [] && $hasFilterOnlyQuery) {
                $wideResults = $this->browseBucket($wideFilters, $searchLimit, $sort);
            } elseif ($wideTokens !== []) {
                if ($this->looksLikeModelCodeQuery($wideTokens)) {
                    $wideResults = $this->modelCodeSearch($wideTokens, $wideFilters, $searchLimit);
                }
                if ($wideResults === []) {
                    $wideResults = $this->fulltextSearch($wideTokens, $wideFilters, $searchLimit, true);
                }
                if ($wideResults === []) {
                    $wideResults = $this->likeSearch($wideTokens, $wideFilters, $searchLimit);
                    if (count($wideTokens) === 1 && !$this->resultsContainNameToken($wideResults, $wideTokens[0])) {
                        $wideResults = [];
                    }
                }
                if ($wideResults === [] && count($wideTokens) >= 3) {
                    $wideResults = $this->descriptivePhraseSearch($wideTokens, $wideFilters, $searchLimit);
                }
            }

            if ($wideResults !== []) {
                $results = $wideResults;
            }
        }

        if ($spinMin !== null || $spinMax !== null) {
            $results = $this->filterBySpinSpeed($results, $spinMin, $spinMax);
        }

        if ($isBareSingleWordQuery && count($results) >= 3) {
            $results = $this->applyDominantCategoryFilter($results);
        }

        if ($targetPrice !== null || $targetCableLength !== null || $spinTarget !== null || $productPreference !== null || $sort !== null || $isBareSingleWordQuery) {
            $results = $this->rankByIntent($results, $targetPrice, $targetCableLength, $productPreference, $sort, $spinTarget);
            $results = array_slice($results, 0, $limit);
        } elseif ($brandOnlyQuery) {
            $results = $this->rankBrandOnlyResults($results);
            $results = array_slice($results, 0, $limit);
        } elseif ($hasSpinConstraint) {
            $results = array_slice($results, 0, $limit);
        }

        foreach ($results as $i => $row) {
            unset($results[$i]['_name_starts'], $results[$i]['_head_word']);
        }

        return $results;
    }

    /**
     * Look up one product by its exact EAN barcode.
     *
     * @param string $ean
     * @return array|null
     */
    public function findByEan($ean)
    {
        $sql = $this->baseSelect() . ' WHERE p.ean = ? LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([trim($ean)]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->shape($row);
    }

    /**
     * @param int $id
     * @return array|null
     */
    public function findById($id)
    {
        $sql = $this->baseSelect() . ' WHERE p.id = ? LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([(int) $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->shape($row);
    }

    /**
     * If the given text clearly names a product type ("racunar", "monitor")
     * that resolves to one of our real categories, return its subcategory
     * name. Used to catch "pokazi mi prvi racunar" when the first item shown
     * was actually a monitor - the ordinal ("prvi") is honoured, but the
     * product-type word the customer used is quietly wrong.
     *
     * @param string $text
     * @return string|null
     */
    public function detectedProductType($text)
    {
        $bucket = $this->bucketForQuery($text, null);
        if ($bucket === null) {
            $bucket = $this->intentBucketForQuery($text);
        }

        if ($bucket === null || $this->isGenericBucketName((string) $bucket['name'])) {
            return null;
        }

        return (string) $bucket['name'];
    }

    /**
     * Which kinds of product we stock for a given brand.
     *
     * Answers the follow-up to a miss: "we have no Samsung laptops, but we do
     * carry Samsung phones, air conditioning and white goods". Without this the
     * assistant can only say no, and the customer leaves.
     *
     * @param string $brand
     * @param int    $limit Number of categories to return.
     * @return array{brand:string,categories:array[]} Empty brand when unknown.
     */
    public function brandCategories($brand, $limit = 4)
    {
        $brand = trim($brand);
        if ($brand === '') {
            return ['brand' => '', 'categories' => []];
        }

        $row = $this->resolveBrandName($brand);

        if ($row === false) {
            return ['brand' => '', 'categories' => []];
        }

        // Supercategories, not categories: the customer wants "mobiteli, klime,
        // bijela tehnika", not "Televizori i oprema / Prijemnici DVB-S".
        $sql = 'SELECT sc.name AS category, COUNT(*) AS n
                FROM products p
                JOIN categories c       ON c.id  = p.category_id
                JOIN supercategories sc ON sc.id = c.super_category_id
                WHERE p.brand_id = ? AND p.stock > 0
                GROUP BY sc.id, sc.name
                ORDER BY n DESC
                LIMIT ' . max(1, min(10, (int) $limit));

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([(int) $row['id']]);

        $categories = [];
        foreach ($stmt->fetchAll() as $r) {
            $categories[] = ['category' => $r['category'], 'products' => (int) $r['n']];
        }

        return ['brand' => $row['name'], 'categories' => $categories];
    }

    /**
     * Resolve the brand name as the catalogue spells it, with a small typo
     * tolerance for transposed letters ("flacom" -> "Falcom").
     *
     * @param string $brand
     * @return array{id:int,name:string}|false
     */
    public function resolveBrandName($brand)
    {
        $brand = trim((string) $brand);
        if ($brand === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, name FROM brands WHERE name LIKE ? ORDER BY CHAR_LENGTH(name) ASC LIMIT 1'
        );
        $stmt->execute(['%' . $brand . '%']);
        $row = $stmt->fetch();
        if ($row !== false) {
            return ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }

        $norm = Text::normalize($brand);
        $best = null;
        $bestDistance = 99;
        foreach ($this->brandMap() as $candidate) {
            $candidateNorm = (string) $candidate['norm'];
            if (mb_strlen($candidateNorm) < 4 || abs(mb_strlen($candidateNorm) - mb_strlen($norm)) > 2) {
                continue;
            }

            $distance = levenshtein($norm, $candidateNorm);
            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        if ($best !== null && $bestDistance <= 2) {
            return ['id' => (int) $best['id'], 'name' => (string) $best['name']];
        }

        return false;
    }

    /**
     * Pull action/promotion words out of a natural product query.
     *
     * "televizori na akciji" should search for televisions while filtering to
     * action products; "koje akcije imate" should browse all action products.
     *
     * @param string $query
     * @return array{query:string,action_only:bool}
     */
    private function extractActionIntent($query)
    {
        $query = (string) $query;
        $norm  = Text::normalize($query);

        $hasAction = preg_match(
            '/\b(?:akcij\w*|popust\w*|snizen\w*|sniz\w*|rasprodaj\w*|promo\w*|promocij\w*)\b/u',
            $norm
        ) === 1;

        if (!$hasAction) {
            return ['query' => $query, 'action_only' => false];
        }

        $query = preg_replace(
            '/\b(?:akcij\w*|popust\w*|sni(?:z|ž)en\w*|sniz\w*|rasprodaj\w*|promo\w*|promocij\w*)\b/iu',
            ' ',
            $query
        );
        $query = preg_replace('/\b\d+(?:[.,]\d+)?\s*(?:%|posto|postotak|procenat|procent\w*)\b/iu', ' ', $query);
        $query = preg_replace('/\b(?:i\s+dalje|idalje|dalje|jos\s+uvijek|jos\s+uvek|uvijek|uvek|ovaj|ova|ovo|ove)\b/iu', ' ', $query);

        return [
            'query'       => trim(preg_replace('/\s+/u', ' ', $query)),
            'action_only' => true,
        ];
    }

    // -----------------------------------------------------------------------

    /**
     * @param string[] $tokens
     * @param array    $filters ['sql' => string, 'params' => array]
     * @param int      $limit
     * @return array[]
     */
    private function fulltextSearch(array $tokens, array $filters, $limit, $strictOnly = false)
    {
        // InnoDB's fulltext index skips tokens shorter than
        // innodb_ft_min_token_size (3 by default), so "A4", "TV", "4K" and "5G"
        // never reach MATCH. Those are re-applied as LIKE constraints instead.
        $indexable = [];
        $short     = [];
        foreach ($tokens as $token) {
            if (mb_strlen($token) >= 3) {
                $indexable[] = $token;
            } else {
                $short[] = $token;
            }
        }

        if ($indexable === []) {
            return [];
        }

        $head     = $this->headToken($indexable, $headConfident);
        $headExpr = '+' . $this->resolveToken($head) . '*';

        $all = '';
        foreach ($indexable as $token) {
            $all .= '+' . $this->resolveToken($token) . '* ';
        }
        $all = trim($all);

        // Pass 1 — every word present, AND the head noun in the product NAME.
        //
        // The name requirement is what stops "gaming miševe" returning gaming
        // headphones: those mention a mouse somewhere in their description and
        // carry "gaming" twice in the name, so on text relevance alone they win.
        // Requiring "miš" in the name means only actual mice qualify.
        //
        // MATCH(name_text) only proves the head word appears SOMEWHERE in the
        // name, not that it's the product's own head word - "Postolje za
        // mašinu za veš" (a washing-machine stand) satisfies "mašin* in
        // name_text" exactly as well as "Mašina za pranje veša" does, they
        // only share the word "mašina" and one is an accessory. Filtering to
        // products whose name actually LEADS with a query token (see
        // nameLeadsWith()) is the real "is this the product, not an
        // accessory mentioning it" signal - filter to that before accepting
        // the pass. Found 2026-08-26: "veš mašine do 300km" returned the
        // stand/feet instead of real washers or an honest "not found".
        $results = $this->filterHeadMatch($this->runFulltext($all, $short, $filters, $limit, $indexable, $headExpr), $indexable);
        if ($results !== []) {
            return $results;
        }

        // The exact thing they asked for exists in the catalogue but is not
        // in stock right now - "Mašina za pranje i usisavanje podova" (a
        // Karcher floor washer/vacuum) is a completely different product
        // from "veš mašina" (laundry washer); they only share the word
        // "mašina". Do not let passes 2/3 below loosen every other word and
        // drift onto whatever else "mašina" matches - report nothing found
        // instead of a misleading substitute from an unrelated product
        // family. Only meaningful when in_stock_only actually narrowed
        // anything; skip the extra query otherwise. Same name_starts filter
        // as Pass 1 above - an out-of-stock accessory must not be mistaken
        // for the real product being out of stock.
        if (strpos($filters['sql'], 'p.stock > 0') !== false) {
            $filtersAnyStock = $filters;
            $filtersAnyStock['sql'] = str_replace(' AND p.stock > 0', '', $filtersAnyStock['sql']);
            $existsButOutOfStock = $this->filterHeadMatch($this->runFulltext($all, $short, $filtersAnyStock, 1, $indexable, $headExpr), $indexable);
            if ($existsButOutOfStock !== []) {
                return [];
            }
        }

        // Pass 2 — head noun still required in the name, other words only boost
        // the ranking. "crveni laptop" lands here when we carry no red laptop:
        // it returns laptops rather than red telephones.
        //
        // Skipped for strictOnly (action_only or a brand filter): dropping
        // every non-head word means a generic head noun ("aparat") alone
        // decides the match, so "aparati za kafu na akciji" with zero
        // discounted coffee machines, or "gorenje aparat za kafu" when
        // Gorenje makes none, would return any matching appliance instead of
        // correctly reporting nothing found for that specific request.
        //
        // Also skipped when headToken() had no real candidate ($headConfident
        // false): dropping every word except a length-guessed "head" is what
        // let "printer laserski" (no printers in stock) return a laser
        // pointer - "laserski" merely outscored "printer" on letter count,
        // not on being the actual product noun. With no confident head,
        // requiring every word (pass 1/3 below) is the only trustworthy
        // signal; a low-confidence guess is not worth searching on alone.
        $loose = $headExpr;
        foreach ($indexable as $token) {
            if ($token !== $head) {
                $loose .= ' ' . $this->resolveToken($token) . '*';
            }
        }
        if (!$strictOnly && $headConfident) {
            $results = $this->filterHeadMatch($this->runFulltext($loose, $short, $filters, $limit, $indexable, $headExpr), $indexable);
            if ($results !== []) {
                return $results;
            }
        }

        // Pass 3 — drop the name requirement. Accessories and description
        // matches become acceptable, which beats returning nothing.
        $results = $this->runFulltext($all, $short, $filters, $limit, $indexable);
        if ($results !== [] && $this->hasHeadMatch($results, $indexable)) {
            return $results;
        }

        if (!$strictOnly && $headConfident) {
            $loose3 = $this->runFulltext($loose, $short, $filters, $limit, $indexable);
            if ($loose3 !== [] && $this->hasHeadMatch($loose3, $indexable)) {
                return $loose3;
            }
        }

        return [];
    }

    /**
     * Does the product's own name LEAD with one of these query words - as
     * its own first or second word, not buried somewhere later in a longer
     * name that is really describing something else? Two words, not just
     * the first: plenty of real product names put a qualifier before the
     * actual product noun ("Benzinska kosilica za travu", "Bežični
     * kontroler PlayStation..."), so requiring strict position 0 wrongly
     * excluded genuine matches. Found 2026-08-26: "kosilica" (lawn mower)
     * alone returned zero results even though real ones are in stock, all
     * named "Benzinska/Električna/Aku kosilica...". Still correctly
     * excludes "Postolje za mašinu za veš" (mašinu is the 3rd word) and
     * "Antivibracijske nogice za mašinu za veš" (4th word) - the
     * accessories this whole mechanism exists to keep out.
     *
     * @param string   $name
     * @param string[] $needles Already resolveToken()'d.
     * @return bool
     */
    private function nameLeadsWith($name, array $needles)
    {
        if ($needles === []) {
            return false;
        }

        $words = preg_split('/\s+/u', trim(Text::normalize((string) $name)));

        // A leading bare number is a spec/count prefix ("5-portni switch"
        // normalizes to "5 portni switch...", a length "10-metara..." to
        // "10 metara..."), never the product-type word itself - drop it
        // before counting "first two words", or it silently eats one of
        // the two slots. Found 2026-08-27: every "N-portni" switch in
        // stock has this shape, so "mrezni switch" matched none of them -
        // "mrezni" was really the name's 3rd word once "5"/"8"/"24" counted
        // as the 1st, one past this check's window.
        $words = array_values(array_filter($words, function ($word) {
            return preg_match('/^\d+$/u', $word) !== 1;
        }));

        foreach (array_slice($words, 0, 2) as $word) {
            foreach ($needles as $needle) {
                if ($needle !== '' && strpos($word, $needle) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Does any result name actually lead with one of the query words?
     *
     * @param array[]  $results
     * @param string[] $tokens
     * @return bool
     */
    private function hasHeadMatch(array $results, array $tokens = [])
    {
        $needles = [];
        foreach ($tokens as $token) {
            $needles[] = $this->resolveToken($token);
        }

        foreach ($results as $row) {
            if ($this->nameLeadsWith(isset($row['name']) ? $row['name'] : '', $needles)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Keep only rows whose name actually LEADS with a query token - the
     * product itself, not something that merely mentions it. See the Pass 1
     * comment in fulltextSearch() for why MATCH() presence isn't enough.
     *
     * @param array[]  $results
     * @param string[] $tokens
     * @return array[]
     */
    private function filterHeadMatch(array $results, array $tokens = [])
    {
        $needles = [];
        foreach ($tokens as $token) {
            $needles[] = $this->resolveToken($token);
        }

        $anchored = [];
        foreach ($results as $row) {
            if ($this->nameLeadsWith(isset($row['name']) ? $row['name'] : '', $needles)) {
                $anchored[] = $row;
            }
        }
        return $anchored;
    }

    /**
     * LIKE fallback may find a word only in a long description. For a single
     * product noun that is too weak: "megafon" must not return a Nintendo game
     * whose description happens to mention a megaphone-like item. Brand matches
     * are still allowed through the brand field.
     *
     * @param array[] $results
     * @param string  $token
     * @return bool
     */
    private function resultsContainNameToken(array $results, $token)
    {
        $needle = $this->resolveToken($token);
        if ($needle === '') {
            return false;
        }

        foreach ($results as $row) {
            $haystack = Text::normalize(
                (isset($row['name']) ? $row['name'] : '') . ' ' .
                (isset($row['brand']) ? $row['brand'] : '')
            );

            if (strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
    /**
     * Shorten a word until it actually matches something in the catalogue.
     *
     * Simple vowel-trimming is not enough for Bosnian. "miševe" trims to
     * "misev", but the product is called "Miš" — the plural inserts a whole
     * syllable, so no amount of vowel-stripping bridges the gap, and the search
     * silently returns nothing of that kind.
     *
     * Instead of encoding every grammar rule, ask the data: try the stem, and
     * if it matches no product name, drop one character and try again. Stop
     * after a couple of characters; otherwise an unknown word such as "razglas"
     * can become "raz*" and match unrelated "razvodna kutija" products.
     *
     * @param string $token Normalised token.
     * @return string
     */
    private function resolveToken($token)
    {
        if (isset($this->tokenCache[$token])) {
            return $this->tokenCache[$token];
        }

        $stem    = Text::stem($token);
        $resolved = $stem;

        $minLen = max(3, mb_strlen($stem) - 2);
        for ($len = mb_strlen($stem); $len >= $minLen; $len--) {
            $candidate = mb_substr($stem, 0, $len);
            if ($this->nameMatchCount($candidate) > 0) {
                $resolved = $candidate;
                break;
            }
        }

        $this->tokenCache[$token] = $resolved;
        return $resolved;
    }

    /**
     * How many product names contain a word starting with this prefix.
     *
     * Uses the fulltext index rather than LIKE — a LIKE '%x%' scan over 10,000
     * rows several times per query would cost more than the search itself.
     *
     * @param string $prefix
     * @return int
     */
    private function nameMatchCount($prefix)
    {
        if (isset($this->countCache[$prefix])) {
            return $this->countCache[$prefix];
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM products WHERE MATCH(name_text) AGAINST (? IN BOOLEAN MODE)'
        );
        $stmt->execute(['+' . $prefix . '*']);
        $count = (int) $stmt->fetchColumn();

        $this->countCache[$prefix] = $count;
        return $count;
    }

    /**
     * The word naming the kind of product wanted.
     *
     * Rarity is the wrong signal: in "crveni laptop" the rarer word is
     * "crveni", and requiring that returns red telephones. What actually marks
     * the product type in this catalog is position — entries are named
     * "Laptop 15.6"..." while accessories are "Torba za laptop". So the head
     * word is the one that most often STARTS a product name.
     *
     * @param string[] $tokens
     * @return string
     */
    private function headToken(array $tokens, &$confident = null)
    {
        // Words that describe compatibility/context ("gume ZA auto", "punjač
        // ZA auto") rather than naming the product itself. Bare "auto"
        // prefixes an enormous number of unrelated product names (Auto
        // akustika, Automobil igračke, Auto antena...), so left unchecked it
        // wins the head-word race for almost any "X za auto" query even when
        // X is the real product and we carry no matching item at all -
        // "gume za auto" (car tires, which this catalog does not carry)
        // otherwise falls back to RC toy cars, because "auto" alone still
        // matches plenty of products once "gume" (zero matches here) gets
        // dropped by the looser fallback passes. Genuine compound names like
        // "auto punjač" are unaffected: "punjač" alone is still a fine head
        // word and real "Punjač auto" products have it in their name too.
        $deprioritized = ['auto'];
        $preferred     = array_values(array_diff($tokens, $deprioritized));
        $candidates    = $preferred !== [] ? $preferred : $tokens;

        $best      = null;
        $bestCount = -1;

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM products WHERE head_word LIKE ?'
        );

        foreach ($candidates as $token) {
            $stmt->execute([$this->resolveToken($token) . '%']);
            $count = (int) $stmt->fetchColumn();

            if ($count > $bestCount) {
                $bestCount = $count;
                $best      = $token;
            }
        }

        // No token starts any product name — fall back to the longest word,
        // which is usually the most specific. This is a guess, not a finding:
        // "printer laserski" with no printers in stock falls back to
        // "laserski" (8 letters beats "printer"'s 7) purely on length, with
        // no sense that "printer" is the actual noun and "laserski" only a
        // descriptor. $confident tells the caller not to trust this guess
        // enough to build a search around it alone.
        if ($bestCount <= 0) {
            $confident = false;
            $best = $candidates[0];
            foreach ($candidates as $token) {
                if (mb_strlen($token) > mb_strlen($best)) {
                    $best = $token;
                }
            }
        } else {
            $confident = true;
        }

        return $best;
    }

    /**
     * Run one boolean-mode query.
     *
     * Relevance weights a name match far above a description match: a product
     * called "Laptop 15.6"" IS a laptop, while "Ruksak za laptop" merely
     * mentions one. Without this weighting, accessories outrank the real thing.
     *
     * @param string   $boolean
     * @param string[] $short   Sub-3-character tokens, applied via LIKE.
     * @param array    $filters
     * @param int      $limit
     * @return array[]
     */
    private function runFulltext($boolean, array $short, array $filters, $limit, array $allTokens = [], $requireInName = null)
    {
        $params = [
            ':qn'  => $boolean,
            ':qs'  => $boolean,
            ':qw'  => $boolean,
        ];
        $params = array_merge($params, $filters['params']);

        $shortSql = '';
        foreach ($short as $i => $token) {
            $key = ':s' . $i;
            $shortSql .= " AND p.search_text LIKE {$key}";
            $params[$key] = '%' . $this->resolveToken($token) . '%';
        }

        // A product whose NAME BEGINS with a query word is the thing itself;
        // one that merely mentions it is usually an accessory for it. This is
        // the difference between "Laptop 15.6"" and "Torba za laptop", and it
        // outranks every other signal.
        $prefixParts = [];
        foreach ($allTokens as $i => $token) {
            $key = ':px' . $i;
            $prefixParts[] = "(CASE WHEN p.name_text LIKE {$key} THEN 1 ELSE 0 END)";
            $params[$key]  = $this->resolveToken($token) . '%';
        }
        $prefixScore = $prefixParts === [] ? '0' : implode(' + ', $prefixParts);

        $relevance = '(MATCH(p.name_text) AGAINST (:qn IN BOOLEAN MODE) * 4'
                   . ' + MATCH(p.search_text) AGAINST (:qs IN BOOLEAN MODE)) AS relevance,'
                   . ' (' . $prefixScore . ') AS name_starts';

        $nameSql = '';
        if ($requireInName !== null) {
            $nameSql = ' AND MATCH(p.name_text) AGAINST (:qh IN BOOLEAN MODE)';
            $params[':qh'] = $requireInName;
        }

        $sql = $this->baseSelect($relevance)
             . ' WHERE MATCH(p.search_text) AGAINST (:qw IN BOOLEAN MODE)'
             . $nameSql
             . $shortSql
             . $filters['sql']
             // In-stock first: a perfect match nobody can buy is a worse answer
             // than a good match sitting on the shelf.
             . ' ORDER BY (p.stock > 0) DESC, name_starts DESC, relevance DESC, p.stock DESC'
             . ' LIMIT ' . (int) $limit;

        return $this->run($sql, $params);
    }
    /**
     * @param string[] $tokens
     * @param array    $filters
     * @param int      $limit
     * @return array[]
     */
    private function modelCodeSearch(array $tokens, array $filters, $limit)
    {
        $clauses = [];
        $params  = [];

        foreach ($tokens as $i => $token) {
            $key = ':mc' . $i;
            $clauses[] = "p.name_text LIKE {$key}";
            $params[$key] = '%' . $this->resolveToken($token) . '%';
        }

        $sql = $this->baseSelect()
             . ' WHERE ' . implode(' AND ', $clauses)
             . $filters['sql']
             . ' ORDER BY (p.stock > 0) DESC, p.stock DESC, p.price IS NULL ASC, p.price ASC'
             . ' LIMIT ' . (int) $limit;

        return $this->run($sql, array_merge($params, $filters['params']));
    }

    /**
     * @param string[] $tokens
     * @return bool
     */
    private function looksLikeModelCodeQuery(array $tokens)
    {
        foreach ($tokens as $token) {
            if (preg_match('/[a-z]/u', $token) && preg_match('/\d/u', $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fallback for product-page/marketing names that live in description more
     * than in the generic product name, e.g. "Dyson Supersonic Nural sušilo za
     * kosu" while the catalog name is only "Fen za kosu, 1600W".
     *
     * @param string[] $tokens
     * @param array    $filters
     * @param int      $limit
     * @return array[]
     */
    private function descriptivePhraseSearch(array $tokens, array $filters, $limit)
    {
        $clauses = [];
        $params  = [];

        foreach ($tokens as $i => $token) {
            $key = ':dp' . $i;
            $clauses[] = "p.search_text LIKE {$key}";
            $params[$key] = '%' . $this->resolveToken($token) . '%';
        }

        $actionOrder = $this->actionColumnsAvailable ? ', p.is_action DESC' : '';

        $sql = $this->baseSelect()
             . ' WHERE ' . implode(' AND ', $clauses)
             . $filters['sql']
             . ' ORDER BY (p.stock > 0) DESC' . $actionOrder . ', p.stock DESC, p.price IS NULL ASC, p.price ASC'
             . ' LIMIT ' . (int) $limit;

        return $this->run($sql, array_merge($params, $filters['params']));
    }

    /**
     * @param string[] $tokens
     * @param array    $filters
     * @param int      $limit
     * @return array[]
     */
    private function likeSearch(array $tokens, array $filters, $limit)
    {
        $clauses = [];
        $params  = [];

        foreach ($tokens as $i => $token) {
            $key = ':t' . $i;
            $clauses[] = "p.search_text LIKE {$key}";
            $params[$key] = '%' . $this->resolveToken($token) . '%';
        }

        // This is the last-resort path (only reached when fulltextSearch()
        // found nothing across all three of its passes), and unlike that
        // function it has no name-anchoring check at all - it accepts a
        // match purely from search_text, which includes the description.
        // Found 2026-08-25: "koliko racunara imate do 500 KM" matched a
        // gamepad here, because its description happens to mention "PC
        // računarima" - a real accessory-mentions-the-main-product false
        // positive, the exact case this codebase already guards against
        // elsewhere (fulltextSearch's hasHeadMatch(), the system prompt's
        // own "do not present an accessory as if it matched" rule). Fetch a
        // wider pool and keep only rows whose own NAME contains one of the
        // search words; if none do, this is the same "nothing real found"
        // outcome as fulltextSearch already gives - a coincidental
        // description mention is not a match.
        $pool = max($limit * 5, 20);

        $sql = $this->baseSelect()
             . ' WHERE ' . implode(' AND ', $clauses)
             . $filters['sql']
             . ' ORDER BY (p.stock > 0) DESC, p.stock DESC'
             . ' LIMIT ' . (int) $pool;

        $rows = $this->run($sql, array_merge($params, $filters['params']));

        $needles = [];
        foreach ($tokens as $token) {
            $needles[] = $this->resolveToken($token);
        }

        // Anchored to the LEAD of the name (its own first or second word),
        // not just a word boundary anywhere in it - found 2026-08-25: a
        // plain mb_strpos() let "jel" (colloquial "je li"/"is it") match
        // inside "bijelo" (white) as a substring coincidence, returning a
        // game controller for "jel ima racunara"; a word-boundary \b fix
        // stopped that, but found 2026-08-26 it was still not enough -
        // "Antivibracijske nogice za mašinu za veš" (a washing-machine
        // accessory) genuinely contains "mašinu" as a real word in its own
        // name, not just a substring fluke, so \b let it through for a "veš
        // mašine" search too. Same nameLeadsWith() rule fulltextSearch uses
        // (see its docblock): the accessory names the machine well past the
        // 2nd word, a real product rarely does.
        $anchored = [];
        foreach ($rows as $row) {
            if ($this->nameLeadsWith(isset($row['name']) ? $row['name'] : '', $needles)) {
                $anchored[] = $row;
            }
            if (count($anchored) >= $limit) {
                break;
            }
        }

        return $anchored;
    }

    /**
     * Browse a known category/subcategory when the customer's query exactly
     * matched the bucket name but the product names do not contain those words.
     *
     * @param array $filters
     * @param int   $limit
     * @param string|null $sort
     * @return array[]
     */
    private function browseBucket(array $filters, $limit, $sort = null)
    {
        if ($sort === 'price_asc') {
            $order = ' ORDER BY (p.stock > 0) DESC, p.price IS NULL ASC, p.price ASC, p.stock DESC';
        } elseif ($sort === 'price_desc') {
            $order = ' ORDER BY (p.stock > 0) DESC, p.price IS NULL ASC, p.price DESC, p.stock DESC';
        } elseif ($sort === 'discount_desc') {
            $order = ' ORDER BY (p.stock > 0) DESC, p.discount_percent IS NULL ASC, p.discount_percent DESC, p.price IS NULL ASC, p.price ASC';
        } else {
            $order = ' ORDER BY (p.stock > 0) DESC, p.stock DESC, p.price IS NULL ASC, p.price ASC';
        }

        $sql = $this->baseSelect()
             . ' WHERE 1=1'
             . $filters['sql']
             . $order
             . ' LIMIT ' . (int) $limit;

        return $this->run($sql, $filters['params']);
    }

    /**
     * @param string[] $tokens
     * @return string|null
     */
    private function productPreference(array $tokens)
    {
        $resolved = [];
        foreach ($tokens as $token) {
            $resolved[] = $this->resolveToken($token);
        }

        if (in_array('monitor', $resolved, true)
            && (in_array('gaming', $resolved, true) || in_array('gejmersk', $resolved, true))
        ) {
            return 'gaming_monitor';
        }

        if (in_array('monitor', $resolved, true)) {
            $nonPcHints = ['vlaznost', 'vlaznosti', 'vlage', 'temperatur', 'temperatura', 'beba', 'baby'];
            foreach ($resolved as $token) {
                if (in_array($token, $nonPcHints, true)) {
                    return null;
                }
            }

            return 'pc_monitor';
        }

        if (in_array('masin', $resolved, true) && (in_array('ves', $resolved, true) || in_array('pranj', $resolved, true))) {
            return 'washing_machine';
        }

        return null;
    }

    /**
     * Intent preferences that depend on the customer's original wording before
     * bucket words are stripped.
     *
     * @param string     $query
     * @param array|null $bucket
     * @return string|null
     */
    private function productPreferenceForQuery($query, $bucket)
    {
        $norm = Text::normalize($query);

        if ($bucket !== null) {
            $hasCableWord = preg_match('/\b(?:kabl\w*|kabel\w*)\b/u', $norm);
            $hasNonCableConnector = preg_match(
                '/\b(?:adapter\w*|konektor\w*|uticnic\w*|uticac\w*|utikac\w*|razdjelnik\w*|razdelnik\w*|odcjepnik\w*|odcepnik\w*|splitter\w*|konverter\w*|prelaz\w*|nastavak\w*|spojnic\w*)\b/u',
                $norm
            );

            if ($hasCableWord && !$hasNonCableConnector) {
                return 'actual_cable';
            }
            if ($hasNonCableConnector && !$hasCableWord) {
                return 'actual_connector';
            }
        }

        if (preg_match('/\bkucist\w*\b/u', $norm) && preg_match('/\b(?:racunar\w*|pc|kompjuter\w*)\b/u', $norm)) {
            return 'pc_case';
        }

        if (preg_match('/\bmaticn\w*\s+ploc\w*\b/u', $norm)
            && preg_match('/\b(?:pc|racunar\w*|kompjuter\w*|igr\w*|gejmer\w*|gaming)\b/u', $norm)
        ) {
            return 'pc_motherboard';
        }

        if (preg_match('/\b(?:napojn\w*|napajanj\w*)\b/u', $norm)
            && preg_match('/\b(?:jedinic\w*|pc|atx|racunar\w*|kompjuter\w*)\b/u', $norm)
        ) {
            return 'pc_power_supply';
        }

        if (preg_match('/\bmonitor\w*\b/u', $norm)
            && preg_match('/\b(?:gaming|gejmer\w*|igr\w*)\b/u', $norm)
        ) {
            return 'gaming_monitor';
        }

        if (preg_match('/\b(?:sto|stol|stolov\w*)\b/u', $norm)
            && preg_match('/\b(?:pc|racunar\w*|kompjuter\w*|gaming|gejmer\w*|igr\w*)\b/u', $norm)
        ) {
            return 'pc_desk';
        }

        if (preg_match('/\bpodlog\w*\b/u', $norm)
            && preg_match('/\b(?:tastatur\w*|mis|misev\w*|gaming|gejmer\w*|igr\w*)\b/u', $norm)
        ) {
            return 'mouse_pad';
        }

        if (preg_match('/\b(?:ssd|hdd|disk\w*)\b/u', $norm)
            && preg_match('/\blaptop\w*\b/u', $norm)
        ) {
            return 'storage_drive';
        }

        if (preg_match('/\bpapir\w*\b/u', $norm)
            && preg_match('/\b(?:printer\w*|stampac\w*|printanj\w*)\b/u', $norm)
        ) {
            return 'printer_paper';
        }

        return null;
    }

    /**
     * @param array $bucket
     * @return string|null
     */
    private function preferenceForBucket(array $bucket)
    {
        $name = Text::normalize((string) $bucket['name']);

        if ($name === 'fotoaparati kamere') {
            return 'photo_camera';
        }

        if ($name === 'monitori' && Text::normalize((string) $bucket['parent']) === 'pc') {
            return 'pc_monitor';
        }

        if ($name === 'masina za pranje vesa') {
            return 'washing_machine';
        }

        if ($name === 'kablovi') {
            return 'actual_cable';
        }

        if ($name === 'audio professional') {
            return 'pro_audio';
        }

        if ($name === 'aparati za brijanje') {
            return 'shaver';
        }

        if ($name === 'stapni usisavaci') {
            return 'stick_vacuum';
        }

        if ($name === 'gaming stolice') {
            return 'gaming_chair';
        }

        return null;
    }

    /**
     * @param array[] $row
     * @return string
     */
    private function groupLabelForRow(array $row)
    {
        if (!empty($row['subcategory'])) {
            return (string) $row['subcategory'];
        }

        return isset($row['category']) ? (string) $row['category'] : '';
    }

    /**
     * Score each distinct category label in a result set by reciprocal rank
     * (1/position), not raw hit count. A category with many low-relevance
     * matches (every "Slušalice sa mikrofonom" headset mentions "mikrofon")
     * must not outweigh a category with fewer but far more relevant matches
     * (the actual "Mikrofoni" products, which fulltext already ranked
     * first). Counting occurrences alone gets this backwards.
     *
     * @param array[]       $results Already ordered by relevance.
     * @param callable|null $labelFn (array $row): string. Defaults to
     *                                groupLabelForRow (subcategory only).
     * @return array<string,float> label => score, sorted descending.
     */
    private function categoryRelevanceScores(array $results, $labelFn = null)
    {
        if ($labelFn === null) {
            $labelFn = [$this, 'groupLabelForRow'];
        }

        $scores = [];
        foreach (array_values($results) as $i => $row) {
            $label = call_user_func($labelFn, $row);
            $scores[$label] = (isset($scores[$label]) ? $scores[$label] : 0) + 1 / ($i + 1);
        }

        arsort($scores);
        return $scores;
    }

    /**
     * When a bare single-word query has no resolved bucket, fulltext can
     * return products that merely share a letter sequence across totally
     * unrelated categories ("ploce" matching LEGO "Ploče za cestu" and an
     * antenna part alongside the real "Ugradbena ploča" cooktops). If one
     * subcategory clearly accounts for most of the matches, keep only that
     * one instead of showing the customer a mixed, confusing list.
     *
     * @param array[] $results
     * @return array[]
     */
    private function applyDominantCategoryFilter(array $results)
    {
        $scores = $this->categoryRelevanceScores($results);
        if (count($scores) <= 1) {
            return $results;
        }

        $leaderLabel = array_key_first($scores);
        $leaderScore = $scores[$leaderLabel];
        $secondScore = array_values($scores)[1];

        if ($leaderScore < 0.5 || $leaderScore < 2 * $secondScore) {
            // No clear winner. Leave the mixed set as-is here; the caller can
            // use topicAmbiguity() to decide whether to ask the customer to
            // clarify instead of guessing.
            return $results;
        }

        return array_values(array_filter($results, function ($row) use ($leaderLabel) {
            return $this->groupLabelForRow($row) === $leaderLabel;
        }));
    }

    /**
     * Check whether a bare single-word query is genuinely ambiguous - spread
     * across multiple unrelated categories with no clear winner - so the
     * caller can ask the customer which one they meant instead of mixing
     * unrelated products into one answer.
     *
     * @param string $query
     * @param array  $options Same shape as search(): in_stock_only, etc.
     * @return array|null ['topic' => string, 'labels' => string[]] (up to 3
     *                     distinct "Category > Subcategory" labels, each
     *                     backed by a meaningful share of the matches), or
     *                     null when the query is not ambiguous (a bucket
     *                     resolved cleanly, one category dominates, or there
     *                     simply were not enough matches to judge).
     */
    public function topicAmbiguity($query, array $options = [])
    {
        $inStockOnly = !empty($options['in_stock_only']);
        $visibilityColumn = (string) config_get('catalog_visibility_column', '');
        if (!in_array($visibilityColumn, ['is_vp', 'is_mp'], true)) {
            $visibilityColumn = null;
        }

        $sortIntent = Text::extractSortIntent($query);
        $budget     = Text::extractBudget($sortIntent['query']);
        $cleanQuery = trim($budget['query']);

        $tokens = Text::tokens($cleanQuery);
        if (count($tokens) !== 1) {
            return null;
        }

        // Brand/platform names ("ps5", "xbox") legitimately spread across
        // several item types (console, games, accessories) that are all
        // still the same coherent shopping intent - unlike a generic noun
        // like "ploce" or "filter", where the spread signals genuine
        // confusion about what the customer even wants.
        if (preg_match('/^(?:ps[2345]|xbox|nintendo|playstation)\w*$/u', $tokens[0])) {
            return null;
        }

        if ($this->bucketForQuery($cleanQuery, null) !== null) {
            return null;
        }
        if ($this->intentBucketForQuery($cleanQuery) !== null) {
            return null;
        }

        $filters = $this->buildFilters($inStockOnly, null, null, null, null, null, false, null, [], $visibilityColumn);
        $results = $this->fulltextSearch($tokens, $filters, 60);
        if ($results === []) {
            $results = $this->likeSearch($tokens, $filters, 60);
        }

        if (count($results) < 3) {
            return null;
        }

        $fullLabel = function (array $row) {
            $category = isset($row['category']) ? (string) $row['category'] : '';
            $sub      = $this->groupLabelForRow($row);
            return $sub !== '' && $sub !== $category ? $category . ' > ' . $sub : $category;
        };

        $scores = $this->categoryRelevanceScores($results, $fullLabel);
        unset($scores['']);

        if (count($scores) <= 1) {
            return null;
        }

        // "Grijalice", "Grijalice > Grijalica zidna" and "Grijalice >
        // Grijalica keramička" are not three different purchase intents -
        // they are the same category split into sub-varieties. Only flag
        // genuine ambiguity when the labels span more than one category.
        $categories = [];
        foreach (array_keys($scores) as $label) {
            $parts = explode(' > ', $label, 2);
            $categories[$parts[0]] = true;
        }
        if (count($categories) <= 1) {
            return null;
        }

        $leader = reset($scores);
        $second = array_values($scores)[1];

        if ($leader >= 0.5 && $leader >= 2 * $second) {
            // One category clearly leads - applyDominantCategoryFilter()
            // already handles this case for the actual result set, this is
            // not the "genuinely unclear" case that needs a question.
            return null;
        }

        // Drop stragglers that only scored well by coincidence, so the
        // clarifying question does not offer something irrelevant alongside
        // the real candidates.
        $threshold = $leader * 0.3;
        $labels = [];
        foreach ($scores as $label => $score) {
            if ($score >= $threshold) {
                $labels[] = $label;
            }
            if (count($labels) >= 3) {
                break;
            }
        }

        if (count($labels) <= 1) {
            return null;
        }

        return ['topic' => $tokens[0], 'labels' => $labels];
    }

    /**
     * "Koje antene imate" resolves cleanly to the "Antene" category, which
     * covers radio/satellite/indoor/terrestrial antennas - completely
     * different physical products. Browsing the category then just lists
     * whatever has the most stock, mixing an accessory or two in with one
     * real antenna and never telling the customer the other types exist.
     * When a query resolves to a whole category (not a specific
     * subcategory) that has more than one real subtype in stock, the
     * customer should be asked which one they want.
     *
     * @param string $query
     * @return array|null ['category' => string, 'options' => string[]], or
     *                     null when the query resolved to something more
     *                     specific than a category, or the category has
     *                     only one real subtype worth asking about.
     */
    public function categorySubtypeChoices($query)
    {
        $bucket = $this->bucketForQuery($query, null);
        if ($bucket === null) {
            $bucket = $this->intentBucketForQuery($query);
        }
        if ($bucket === null || $bucket['type'] !== 'category') {
            return null;
        }

        $sql = 'SELECT sc.name AS name, SUM(p.stock > 0) AS in_stock
                FROM products p
                LEFT JOIN subcategories sc ON sc.id = p.subcategory_id
                WHERE p.category_id = ?
                GROUP BY sc.name';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([(int) $bucket['id']]);

        $options = [];
        foreach ($stmt->fetchAll() as $row) {
            $name = isset($row['name']) ? (string) $row['name'] : '';
            if ($name === '' || (int) $row['in_stock'] <= 0 || $this->isGenericBucketName($name)) {
                continue;
            }
            $options[] = $name;
        }

        if (count($options) < 2) {
            return null;
        }

        sort($options);

        return ['category' => (string) $bucket['name'], 'options' => $options];
    }

    /**
     * Broad wording such as "gaming oprema", "USB oprema", "stvari za laptop"
     * means the customer has not picked a concrete product type yet. Group
     * real matching products by their catalog type and ask the customer to
     * choose one instead of showing a random product slice.
     *
     * @param string $query
     * @param int    $limit
     * @return array{topic:string,options:array{label:string,query:string}[]}|null
     */
    public function broadTypeChoicesForQuery($query, $limit = 8)
    {
        $norm = Text::normalize($query);
        if (preg_match('/\b(?:oprem\w*|stvar\w*|asortiman\w*|ponud\w*|artik\w*|proizvod\w*)\b/u', $norm) !== 1) {
            return null;
        }

        $clean = preg_replace('/\b(?:oprem\w*|stvar\w*|asortiman\w*|ponud\w*|artik\w*|proizvod\w*|imate|ima|koj\w*|kakv\w*|sta|sto|mi|nam|vas|kod\s+vas)\b/u', ' ', $norm);
        $clean = trim(preg_replace('/\s+/u', ' ', (string) $clean));
        if ($clean === '') {
            return null;
        }

        $matches = $this->search($clean, ['limit' => 120, 'in_stock_only' => true]);
        if (count($matches) < 4) {
            return null;
        }

        $groups = [];
        foreach ($matches as $row) {
            $subcategory = isset($row['subcategory']) ? trim((string) $row['subcategory']) : '';
            $category = isset($row['category']) ? trim((string) $row['category']) : '';
            $label = $subcategory !== '' && !$this->isGenericBucketName($subcategory) ? $subcategory : $category;
            if ($label === '' || $this->isGenericBucketName($label)) {
                continue;
            }

            $key = Text::normalize($category . ' > ' . $label);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'label' => $this->friendlyTypeLabel($label, $clean),
                    'query' => $this->typeChoiceQuery($label, $clean),
                    'n'     => 0,
                ];
            }
            $groups[$key]['n']++;
        }

        $nameGroups = $this->nameTypeChoicesForBroadMatches($matches, $clean);
        if (count($nameGroups) >= 2 && (count($groups) < 2 || $this->shouldPreferNameTypeChoices($groups, $clean))) {
            $groups = $nameGroups;
        }

        if (count($groups) < 2) {
            return null;
        }

        uasort($groups, function ($a, $b) {
            if ((int) $a['n'] === (int) $b['n']) {
                return strcmp((string) $a['label'], (string) $b['label']);
            }
            return (int) $a['n'] > (int) $b['n'] ? -1 : 1;
        });

        $options = [];
        foreach (array_slice($groups, 0, max(2, min(12, (int) $limit))) as $group) {
            $options[] = ['label' => (string) $group['label'], 'query' => (string) $group['query']];
        }

        return ['topic' => $clean, 'options' => $options];
    }

    /**
     * Category labels like "Periferija" and "Namještaj" are technically
     * correct but weak answers to broad browse questions such as "uredska
     * oprema". If product names reveal concrete types, prefer those.
     *
     * @param array<string,array{label:string,query:string,n:int}> $groups
     * @param string                                               $topic
     * @return bool
     */
    private function shouldPreferNameTypeChoices(array $groups, $topic)
    {
        $generic = 0;
        foreach ($groups as $group) {
            $label = Text::normalize((string) $group['label']);
            if (preg_match('/\b(?:periferija|namjestaj|namještaj|ostalo|oprema|dodaci|razno)\b/u', $label) === 1) {
                $generic++;
            }
        }

        if ($generic >= 1 && preg_match('/\b(?:uredsk\w*|gaming|usb|laptop|mobitel|telefon)\b/u', Text::normalize($topic)) === 1) {
            return true;
        }

        return $generic >= 2;
    }

    /**
     * @param array[] $matches
     * @param string  $topic
     * @return array<string,array{label:string,query:string,n:int}>
     */
    private function nameTypeChoicesForBroadMatches(array $matches, $topic)
    {
        $groups = [];
        foreach ($matches as $row) {
            $label = $this->typeLabelFromProductName(isset($row['name']) ? (string) $row['name'] : '', $topic);
            if ($label === '') {
                continue;
            }

            $key = Text::normalize($label);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'label' => $label,
                    'query' => 'koje ' . $label . ' imate',
                    'n'     => 0,
                ];
            }
            $groups[$key]['n']++;
        }

        return $groups;
    }

    /**
     * @param string $name
     * @param string $topic
     * @return string
     */
    private function typeLabelFromProductName($name, $topic)
    {
        $norm = Text::normalize($name);
        $topicNorm = Text::normalize($topic);
        $prefix = '';
        if (preg_match('/\buredsk\w*\b/u', $topicNorm)) {
            $prefix = 'uredske ';
        } elseif (preg_match('/\bgaming\b/u', $topicNorm)) {
            $prefix = 'gaming ';
        }

        $patterns = [
            '/\bstolic\w*\b/u' => 'stolice',
            '/\bormar\w*\b/u' => 'ormarići',
            '/\bpunjac\w*\b/u' => 'punjači',
            '/\b(?:drzac\w*|nosac\w*)\b/u' => 'držači',
            '/\b(?:kabl\w*|kabel\w*)\b/u' => 'kablovi',
            '/\b(?:mis|misev\w*)\b/u' => 'miševi',
            '/\btastatur\w*\b/u' => 'tastature',
            '/\bslusalic\w*\b/u' => 'slušalice',
            '/\bmonitor\w*\b/u' => 'monitori',
            '/\bprinter\w*\b/u' => 'printeri',
        ];

        foreach ($patterns as $pattern => $label) {
            if (preg_match($pattern, $norm) === 1) {
                if (preg_match('/\buredsk\w*\b/u', $topicNorm)) {
                    if ($label === 'ormarići') {
                        return 'uredski ormarići';
                    }
                    if ($label === 'stolice') {
                        return 'uredske stolice';
                    }
                }
                if ($prefix !== '' && strpos(Text::normalize($label), trim($prefix)) === false) {
                    return $prefix . $label;
                }
                return $label;
            }
        }

        return '';
    }

    /**
     * @param string $label
     * @param string $topic
     * @return string
     */
    private function friendlyTypeLabel($label, $topic)
    {
        $label = trim((string) $label);
        if ($label === '') {
            return '';
        }

        $normTopic = Text::normalize($topic);
        $normLabel = Text::normalize($label);
        if (strpos($normLabel, $normTopic) !== false || strpos($normTopic, $normLabel) !== false) {
            return $label;
        }

        if (preg_match('/\bgaming\b/u', $normTopic)
            && strpos($normLabel, 'gaming') === false
            && preg_match('/\b(?:mis|misev|tastatur|slusalic|stolic|monitor|podlog)\w*\b/u', $normLabel)
        ) {
            return 'gaming ' . mb_strtolower($label);
        }

        return $label;
    }

    /**
     * @param string $label
     * @param string $topic
     * @return string
     */
    private function typeChoiceQuery($label, $topic)
    {
        $friendly = $this->friendlyTypeLabel($label, $topic);
        if ($friendly !== '') {
            return 'koje ' . $friendly . ' imate';
        }

        return 'koje ' . trim((string) $label) . ' imate';
    }

    /**
     * When a query resolves to a bucket that itself has no further subtype
     * split ("monitori", "veš mašine" - already a specific enough
     * subcategory, unlike "Antene" above), but carries several different
     * brands in stock, ask which brand instead of picking 3 essentially at
     * random and hiding the rest. Brand only, for now - grouping by a spec
     * like screen size or capacity needs pulling structured attributes out
     * of free-text product names, which is a separate piece of work.
     *
     * @param string $query
     * @return array{category:string,options:string[]}|null
     */
    public function brandChoicesForQuery($query)
    {
        $bucket = $this->bucketForQuery($query, null);
        if ($bucket === null) {
            $bucket = $this->intentBucketForQuery($query);
        }
        if ($bucket === null) {
            return null;
        }

        // A query that only names the bucket itself ("kabeli napojni") means
        // "show me everything in this category" - brands across the WHOLE
        // bucket are the right thing to offer. But a query that narrows
        // further within a bucket holding more than one kind of product
        // ("kabl za laptop" inside "Kabeli napojni", which also holds PC and
        // stove cords) needs that narrowing carried through - both into
        // which brands get counted, and into what a clicked chip searches
        // for. Found 2026-08-26: asking about a laptop cable got offered
        // "ZED electronic or USE" for the WHOLE bucket, and either chip then
        // browsed stove/PC cords, losing "laptop" entirely.
        $bucketQuery = $this->queryForBucketSearch($query, $bucket);
        $isBareBucketBrowse = $this->isExactBucketNameQuery($query, $bucket)
            || Text::meaningfulTokens($bucketQuery) === [];

        if ($isBareBucketBrowse) {
            $column = $bucket['type'] === 'subcategory' ? 'subcategory_id' : 'category_id';

            $sql = "SELECT b.id AS id, b.name AS name, COUNT(*) AS n
                    FROM products p
                    JOIN brands b ON b.id = p.brand_id
                    WHERE p.{$column} = ? AND p.stock > 0 AND b.name <> ''
                    GROUP BY b.id, b.name
                    ORDER BY n DESC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([(int) $bucket['id']]);
            $rows = $stmt->fetchAll();
            $followUpText = (string) $bucket['name'];
        } else {
            // Narrowed within the bucket - count brands only among products
            // that actually match the narrowing, using the real search
            // pipeline (not a separate hand-rolled query) so this can never
            // drift out of sync with what a click on a resulting chip would
            // itself find.
            $matches = $this->search($query, ['limit' => 50]);
            $counts = [];
            $ids = [];
            foreach ($matches as $row) {
                $brandName = (string) $row['brand'];
                if ($brandName === '') {
                    continue;
                }
                if (!isset($counts[$brandName])) {
                    $counts[$brandName] = 0;
                }
                $counts[$brandName]++;
                if (isset($row['brand_id'])) {
                    $ids[$brandName] = (int) $row['brand_id'];
                }
            }
            arsort($counts);
            $rows = [];
            foreach ($counts as $name => $n) {
                $rows[] = ['id' => isset($ids[$name]) ? (int) $ids[$name] : 0, 'name' => $name, 'n' => $n];
            }
            $followUpText = trim((string) $bucketQuery) !== '' ? (string) $bucketQuery : (string) $bucket['name'];
        }

        if (count($rows) < 2) {
            return null;
        }

        // The customer already named a brand ("Samsung monitor") - do not
        // ask again, just search normally with that brand as a filter.
        $norm = Text::normalize($query);
        foreach ($rows as $row) {
            $brandName = (string) $row['name'];
            if ($brandName !== '' && strpos($norm, Text::normalize($brandName)) !== false) {
                return null;
            }
        }

        // Chip-friendly cap: the most-stocked brands cover most customers,
        // and anyone after a less common brand can still just name it
        // directly next time - that skips this check via the loop above.
        $rows = array_slice($rows, 0, 8);

        $categoryName = (string) $bucket['name'];

        $options = [];
        foreach ($rows as $row) {
            $brandName = (string) $row['name'];
            // The chip shows just the brand ("Samsung"), but a bare brand
            // name typed back on its own loses the product type entirely -
            // MockChatModel's brand fallback reads it as "do you carry
            // Samsung at all" and lists Samsung's whole range instead of
            // resuming "televizori". Send the brand PLUS the category name
            // (or, when the question narrowed further within the bucket,
            // that narrowed text instead - see $followUpText above) as one
            // self-contained query, so clicking a chip does not depend on
            // conversation-context carrying over correctly.
            $options[] = ['label' => $brandName, 'query' => $brandName . ' ' . $followUpText];
            $options[count($options) - 1]['brand_id'] = isset($row['id']) ? (int) $row['id'] : 0;
            $options[count($options) - 1]['products'] = isset($row['n']) ? (int) $row['n'] : 0;
            $options[count($options) - 1]['image'] = $this->brandImageUrl(isset($row['id']) ? (int) $row['id'] : 0);
        }

        return ['category' => $categoryName, 'options' => $options];
    }

    /**
     * @param int $brandId
     * @return string|null
     */
    private function brandImageUrl($brandId)
    {
        $brandId = (int) $brandId;
        if ($brandId <= 0) {
            return null;
        }

        $base = rtrim((string) config_get('brand_image_base_url', config_get('shop_base_url', 'https://www.digitalis.ba')), '/');

        return $base . '/webshop/brand-load/?id=' . $brandId . '&sm';
    }

    /**
     * All brands we stock for a product type, most-carried first - for the
     * AI tool that answers "koje marke tastatura imate" directly, as
     * opposed to brandChoicesForQuery() above, which is the deterministic
     * path's "ask the customer to pick one" chip prompt and deliberately
     * caps/filters/suppresses itself for that purpose (max 8, none if the
     * customer already named a brand, none at all under 2 brands). This
     * method has no such restrictions - it exists purely to answer the
     * question asked, not to decide whether to ask a follow-up.
     *
     * @param string $query
     * @param int    $limit
     * @return array{category:string,brands:array{id:int,name:string,products:int,image:?string}[]}|null
     */
    public function brandsForQuery($query, $limit = 10)
    {
        $bucket = $this->bucketForQuery($query, null);
        if ($bucket === null) {
            $bucket = $this->intentBucketForQuery($query);
        }
        if ($bucket === null) {
            return null;
        }

        $column = $bucket['type'] === 'subcategory' ? 'subcategory_id' : 'category_id';

        $sql = "SELECT b.id AS id, b.name AS name, COUNT(*) AS n
                FROM products p
                JOIN brands b ON b.id = p.brand_id
                WHERE p.{$column} = ? AND p.stock > 0 AND b.name <> ''
                GROUP BY b.id, b.name
                ORDER BY n DESC
                LIMIT " . max(1, min(30, (int) $limit));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([(int) $bucket['id']]);

        $brands = [];
        foreach ($stmt->fetchAll() as $row) {
            $brandId = (int) $row['id'];
            $brands[] = [
                'id'       => $brandId,
                'name'     => (string) $row['name'],
                'products' => (int) $row['n'],
                'image'    => $this->brandImageUrl($brandId),
            ];
        }

        if ($brands === []) {
            return null;
        }

        return ['category' => (string) $bucket['name'], 'brands' => $brands];
    }

    /**
     * @param string $query
     * @return bool
     */
    public function hasProductBucketForQuery($query)
    {
        if ($this->bucketForQuery($query, null) !== null) {
            return true;
        }

        return $this->intentBucketForQuery($query) !== null;
    }

    /**
     * @param string $query
     * @return bool
     */
    public function hasProductBucketAfterBrandExtraction($query)
    {
        $brand = $this->extractBrand($query);
        if ($brand === null) {
            return $this->hasProductBucketForQuery($query);
        }

        return $this->hasProductBucketForQuery($brand['query']);
    }

    /**
     * @param string $query
     * @return bool
     */
    public function hasBrandMention($query)
    {
        return $this->extractBrand($query) !== null;
    }

    /**
     * Detect when the query is actually a category/subcategory name.
     *
     * @param string $query
     * @return array|null ['type' => 'category|subcategory', 'id' => int]
     */
    private function bucketForQuery($query, $scopeSupercategoryId = null)
    {
        $key = $this->bucketKey($query);
        if ($key === '') {
            return null;
        }

        $map = $this->bucketMap();

        foreach ($this->hardBucketAliases() as $alias => $needle) {
            if ($this->bucketKey($alias) !== $key) {
                continue;
            }

            $entry = $this->findBucketEntry($map, $needle['type'], $needle['name'], $needle['parent'], $scopeSupercategoryId);
            if ($entry !== null) {
                return $entry;
            }
        }

        if (!isset($map[$key])) {
            return null;
        }

        $entries = [];
        foreach ($map[$key] as $entry) {
            if ($this->bucketEntryMatchesScope($entry, $scopeSupercategoryId)) {
                $entries[] = $entry;
            }
        }

        if (count($entries) !== 1) {
            $selfNamedCategory = [];
            foreach ($entries as $entry) {
                if ($entry['type'] === 'category' && Text::normalize((string) $entry['name']) === Text::normalize((string) $entry['parent'])) {
                    $selfNamedCategory[] = $entry;
                }
            }

            if (count($selfNamedCategory) === 1) {
                return $selfNamedCategory[0];
            }

            return null;
        }

        return $entries[0];
    }

    /**
     * Detect product intent that is not written exactly like a bucket name.
     *
     * @param string $query
     * @return array|null ['type' => 'category|subcategory', 'id' => int]
     */
    private function intentBucketForQuery($query)
    {
        $norm = Text::normalize($query);
        $map  = $this->bucketMap();

        $hasCableWord = preg_match('/\b(?:kabl\w*|kabel\w*)\b/u', $norm);
        $hasAdapterWord = preg_match('/\b(?:adapter\w*|konektor\w*|uticnic\w*|uticac\w*|utikac\w*)\b/u', $norm);

        if (preg_match('/^(?:pokazi\s+mi\s+)?audio$/u', $norm)) {
            return $this->findBucketEntry($map, 'supercategory', 'Audio', null);
        }

        if (preg_match('/\b(?:hdmi)\b/u', $norm)) {
            if (preg_match('/\b(?:razdjelnik\w*|razdelnik\w*|splitter\w*)\b/u', $norm)) {
                return $this->findBucketEntry($map, 'subcategory', 'HDMI razdjelnici', 'HDMI & Video');
            }
            if ($hasAdapterWord || preg_match('/\b(?:konverter\w*|prelaz\w*|nastavak\w*)\b/u', $norm)) {
                return $this->findBucketEntry($map, 'subcategory', 'HDMI adapteri', 'HDMI & Video');
            }
            if ($hasCableWord || preg_match('/\b(?:hdmi)\b/u', $norm)) {
                return $this->findBucketEntry($map, 'subcategory', 'HDMI', 'HDMI & Video');
            }
        }

        if (preg_match('/\b(?:scart)\b/u', $norm)) {
            if (preg_match('/\b(?:razdjelnik\w*|razdelnik\w*)\b/u', $norm)) {
                return $this->findBucketEntry($map, 'subcategory', 'Scart razdjelnici', 'HDMI & Video');
            }
            if (preg_match('/\b(?:rca|cinc|cinch)\b/u', $norm)) {
                return $this->findBucketEntry($map, 'subcategory', 'Scart - RCA', 'HDMI & Video');
            }
            return $this->findBucketEntry($map, 'subcategory', 'Scart', 'HDMI & Video');
        }

        if (preg_match('/\b(?:s\s*vhs|svhs|s\s+vhs)\b/u', $norm)) {
            return $this->findBucketEntry($map, 'subcategory', 'S-VHS', 'HDMI & Video');
        }

        if (preg_match('/\b(?:display\s*port|displayport|dp)\b/u', $norm)) {
            return $this->findBucketEntry($map, 'subcategory', 'Display Port', 'USB & PC');
        }

        if (preg_match('/\bdvi\b/u', $norm) && !preg_match('/\bvga\b/u', $norm)) {
            if ($hasCableWord) {
                return $this->findBucketEntry($map, 'subcategory', 'HDMI', 'HDMI & Video');
            }
            if ($hasAdapterWord || preg_match('/\b(?:konverter\w*|prelaz\w*|nastavak\w*)\b/u', $norm)) {
                return $this->findBucketEntry($map, 'subcategory', 'VGA/DVI', 'USB & PC');
            }
        }

        if (preg_match('/\b(?:vga|dvi)\b/u', $norm)) {
            return $this->findBucketEntry($map, 'subcategory', 'VGA/DVI', 'USB & PC');
        }

        if (preg_match('/\busb\b/u', $norm)) {
            if (preg_match('/\b(?:printer\w*|stampac\w*|stampač\w*)\b/u', $norm)) {
                return $this->findBucketEntry($map, 'subcategory', 'USB kabel za printer', 'USB & PC');
            }
            if (preg_match('/\b(?:produzn\w*|produžn\w*|produzet\w*|produžet\w*|extension|hub)\b/u', $norm)) {
                return $this->findBucketEntry($map, 'subcategory', 'USB produžni kabel', 'USB & PC');
            }
        }

        // "mrezni switch/ruter" means networking equipment (PC & Game >
        // Ethernet), not a cable - only fall into the cable routing below
        // when no device word says otherwise.
        $networkDeviceWord = preg_match(
            '/\b(?:switch\w*|svic\w*|ruter\w*|router\w*|access\s*point\w*|pristupn\w*\s+tack\w*|repetitor\w*|extender\w*|kartic\w*)\b/u',
            $norm
        );

        $networkTerms = !$networkDeviceWord && preg_match('/\b(?:network|lan|ethernet|internet|mrezn\w*|mrežn\w*|utp|ftp|rj45|patch|cat5\w*|cat6\w*)\b/u', $norm);
        if ($networkTerms) {
            if ($hasAdapterWord) {
                return $this->findBucketEntry($map, 'subcategory', 'Adapteri i konektori', 'Network');
            }
            if (preg_match('/\b(?:na\s+metar|metar|metre|metri|305|100\s+met)\b/u', $norm)) {
                return $this->findBucketEntry($map, 'subcategory', 'Mrežni kabel na metar', 'Network');
            }
            return $this->findBucketEntry($map, 'subcategory', 'Patch kablovi', 'Network');
        }

        $tvSatTerms = preg_match('/\b(?:koaksijaln\w*|coax\w*|rg\s*6|rg6|antensk\w*|kablovsk\w*|kabelsk\w*|tv\s+sat|sat\s+tv)\b/u', $norm);
        if ($tvSatTerms) {
            if (preg_match('/\b(?:odcjepnik\w*|odcepnik\w*)\b/u', $norm)) {
                return $this->findBucketEntry($map, 'subcategory', 'Odcjepnici za kabelsku', 'TV & SAT');
            }
            if (preg_match('/\b(?:razdjelnik\w*|razdelnik\w*|splitter\w*)\b/u', $norm)) {
                return $this->findBucketEntry($map, 'subcategory', 'Razdjelnici', 'TV & SAT');
            }
            if ($hasAdapterWord) {
                return $this->findBucketEntry($map, 'subcategory', 'Adapteri i konektori', 'TV & SAT');
            }
            return $this->findBucketEntry($map, 'subcategory', 'Kabeli koaksijalni', 'TV & SAT');
        }

        $phoneCableTerms = preg_match('/\b(?:telefonsk\w*|telefonij\w*|rj11|dsl)\b/u', $norm);
        if ($phoneCableTerms || preg_match('/\b(?:kabl\w*|kabel\w*)\s+za\s+slusalic\w*\b/u', $norm)) {
            if (preg_match('/\bslusalic\w*\b/u', $norm)) {
                return $this->findBucketEntry($map, 'subcategory', 'Kabel za slušalicu', 'Telefonija');
            }
            if ($hasAdapterWord) {
                return $this->findBucketEntry($map, 'subcategory', 'Adapteri i konektori', 'Telefonija');
            }
            if (preg_match('/\b(?:na\s+metar|metar|metre|metri)\b/u', $norm)) {
                return $this->findBucketEntry($map, 'subcategory', 'Telefonski kabeli na metar', 'Telefonija');
            }
            return $this->findBucketEntry($map, 'subcategory', 'Telefonski kabel', 'Telefonija');
        }

        // Power cords ("produzni kabl", "motalica za kabl", "strujni kabl")
        // and camera/security cables ("kabl za kameru") must not fall into
        // the mobile-cable routing below just because they also contain the
        // bare word "kabl".
        // Found 2026-08-26 on zed.hr: "kabele za laptop" (and "kabel za
        // laptop"/"kabl za laptop") landed on "Kabeli za mobitel, tablet
        // ..." - a laptop is neither a mobile nor a tablet - leaving the
        // real product ("Kabl napojni za laptop", Kabeli napojni/Strujni
        // kabeli) unreachable. Add "laptop" as its own exclusion here,
        // same shape as the existing power-cord/camera exclusions below.
        $powerCableWord = preg_match(
            '/\b(?:produzn\w*|strujn\w*|napojn\w*|motalic\w*|razdjelnik\w*|razdelnik\w*|kamer\w*|video\w*|nadzor\w*|laptop\w*)\b/u',
            $norm
        );

        // Widened from a literal ["kabl","kabel","kabeli","kabli"] list -
        // found 2026-08-26 it missed real conjugations like "kabele"/
        // "kablove" that every other cable-word regex in this file already
        // catches via "kabl\w*|kabel\w*". That inconsistency let "kabele za
        // laptop" slip past a fix aimed only at "kabel za laptop" by taking
        // a different code path here.
        $mobileAccessory = !$powerCableWord && preg_match(
            '/\b(?:maska|maske|masku|futrola|futrole|futrolu|etui|punjac\w*|kabl\w*|kabel\w*|zastita|zastite|zastitno|staklo|stalak|stalci|stalka|postolje|postolja|drzac|drzaci|drzač|powerbank|baterija|remen)\b/u',
            $norm
        );

        if ($mobileAccessory) {
            $isIphone = preg_match('/\b(?:iphone|apple)\b/u', $norm);
            $isTablet = preg_match('/\b(?:tablet\w*|ipad|tab)\b/u', $norm);

            if (preg_match('/\b(?:maska|maske|masku)\b/u', $norm)) {
                if ($isIphone) {
                    return $this->findBucketEntry($map, 'subcategory', 'Maska za iPhone', 'Oprema i dodaci');
                }
                if ($isTablet) {
                    return $this->findBucketEntry($map, 'subcategory', 'Futrole / etui za tablet', 'Oprema i dodaci');
                }
                return $this->findBucketEntry($map, 'subcategory', 'Maska za Smartphone', 'Oprema i dodaci');
            }

            if (preg_match('/\b(?:futrola|futrole|futrolu|etui)\b/u', $norm)) {
                if ($isTablet) {
                    return $this->findBucketEntry($map, 'subcategory', 'Futrole / etui za tablet', 'Oprema i dodaci');
                }
                if ($isIphone) {
                    return $this->findBucketEntry($map, 'subcategory', 'Futrole FLIP - iPhone', 'Oprema i dodaci');
                }
                return $this->findBucketEntry($map, 'subcategory', 'Futrole FLIP - Smartphone', 'Oprema i dodaci');
            }

            if (preg_match('/\b(?:zastita|zastite|zastitn\w*|staklo|folija|folije)\b/u', $norm)) {
                if ($isIphone) {
                    return $this->findBucketEntry($map, 'subcategory', 'Zaštita za ekran - iPhone', 'Oprema i dodaci');
                }
                return $this->findBucketEntry($map, 'subcategory', 'Zaštita za ekran - Smartphone', 'Oprema i dodaci');
            }

            if (preg_match('/\b(?:kabl|kabel|kabeli|kabli)\b/u', $norm)) {
                if (preg_match('/\b(?:pametn\w*\s+sat\w*|smartwatch|smart\s+watch|sat\w*)\b/u', $norm)) {
                    return $this->findBucketEntry($map, 'subcategory', 'Kabl za pametni sat', 'Data & Punjenje');
                }
                return $this->findBucketEntry($map, 'subcategory', 'Kabeli za mobitel, tablet ...', 'Data & Punjenje');
            }

            if (preg_match('/\b(?:punjac\w*)\b/u', $norm)) {
                if (preg_match('/\b(?:auto|auta|autu|automobil\w*)\b/u', $norm)) {
                    return $this->findBucketEntry($map, 'subcategory', 'Punjač auto', 'Data & Punjenje');
                }
                return $this->findBucketEntry($map, 'subcategory', 'Punjač kućni ', 'Data & Punjenje');
            }

            if (preg_match('/\b(?:remen|remeni)\b/u', $norm)) {
                return $this->findBucketEntry($map, 'subcategory', 'Remen za pametni sat', 'Oprema i dodaci');
            }

            // "Stalak"/"postolje" (stand) is a real, natural synonym for
            // "držač" (holder) - found 2026-08-27: real products are all
            // named "Držač za telefon/tablet/smartphone..." or "Selfie
            // stick...", never "stalak"/"postolje", so a customer using
            // that word alone matched nothing (and without this branch,
            // "stalak"/"postolje" isn't in the trigger regex above either,
            // so it fell all the way through to the telefon->Smartphone
            // routing below and searched smartphones for the word "stalak").
            if (preg_match('/\b(?:stalak|stalci|stalka|postolje|postolja)\b/u', $norm)) {
                return $this->findBucketEntry($map, 'subcategory', 'Selfie stick, držač', 'Oprema i dodaci');
            }
        }

        $fixedPhone = preg_match('/\b(?:stoln\w*|fiksn\w*|dect|bezicn\w*\s+telefon\w*)\b/u', $norm);
        if (!$mobileAccessory && !$fixedPhone && preg_match('/\b(?:mobitel\w*|smartphone\w*|mobiln\w*\s+telefon\w*|telefon\w*)\b/u', $norm)) {
            if (preg_match('/\b(?:obicn\w*|tipk\w*|senior)\b/u', $norm)) {
                return $this->findBucketEntry($map, 'subcategory', 'Mobilni telefoni(obični)', 'Prijenosni uređaji');
            }

            return $this->findBucketEntry($map, 'subcategory', 'Smartphone', 'Prijenosni uređaji');
        }

        if (preg_match('/\b(?:foto\s*aparat\w*|fotoaparat\w*)\b/u', $norm)) {
            return $this->findBucketEntry($map, 'subcategory', 'Fotoaparati / Kamere', 'Prijenosni uređaji');
        }

        $professionalAudio = preg_match('/\b(?:profesionaln\w*|professional)\b/u', $norm)
                            && preg_match('/\b(?:audio|zvucnik\w*|mikrofon\w*|pojacal\w*|razglas|ozvucenj\w*)\b/u', $norm);

        if (preg_match('/\b(?:dijel\w*|del\w*)\b/u', $norm)
            && preg_match('/\b(?:zvucnik\w*|zvucnic\w*)\b/u', $norm)
        ) {
            return $this->findBucketEntry($map, 'subcategory', 'Dijelovi za zvučnike', 'Audio professional');
        }

        if (preg_match('/\bmikro\s+prekidac\w*\b/u', $norm) || preg_match('/\bmikroprekidac\w*\b/u', $norm)) {
            return $this->findBucketEntry($map, 'subcategory', 'Mikro prekidači', 'Audio professional');
        }

        if (($professionalAudio || preg_match('/\b(?:razglas|ozvucenj\w*|mikset\w*)\b/u', $norm))
            && preg_match('/\b(?:pojacal\w*|mikset\w*|razglas|ozvucenj\w*)\b/u', $norm)
        ) {
            return $this->findBucketEntry($map, 'subcategory', '110V pojačalo i zvučnici', 'Audio professional');
        }

        if ($professionalAudio && preg_match('/\b(?:zvucnik\w*|zvucnic\w*)\b/u', $norm)) {
            return $this->findBucketEntry($map, 'subcategory', 'Zvučnici', 'Audio professional');
        }

        if ($professionalAudio && preg_match('/\bmikrofon\w*\b/u', $norm)) {
            return $this->findBucketEntry($map, 'subcategory', 'Mikrofoni', 'Audio professional');
        }

        if (preg_match('/\b(?:audio\s+professional|professional\s+audio|profesionaln\w*\s+audio|audio\s+profesionaln\w*)\b/u', $norm)) {
            return $this->findBucketEntry($map, 'category', 'Audio professional', null);
        }

        $videoAdapter       = preg_match('/\b(?:hdmi|vga|displayport|dvi)\b/u', $norm);
        $hasAudioCableWord  = preg_match('/\b(?:kabl\w*|kabel\w*)\b/u', $norm);
        $hasAudioConnector  = preg_match('/\b(?:aux|rca|cinc|cinch|jack)\b/u', $norm)
                            || preg_match('/\b3\s+5(?:\s*mm)?\b/u', $norm);

        if (!$videoAdapter && preg_match('/\b(?:adapter\w*|konektor\w*)\b/u', $norm)
            && preg_match('/\b(?:audio|slusalic\w*|jack|aux|rca|cinc|cinch|usb|type\s+c)\b/u', $norm)
        ) {
            return $this->findBucketEntry($map, 'subcategory', 'Adapteri i konektori', 'Audio');
        }

        if (preg_match('/\b(?:toslink|optick\w*)\b/u', $norm)
            && preg_match('/\b(?:kabl\w*|kabel\w*|audio|toslink|optick\w*)\b/u', $norm)
        ) {
            return $this->findBucketEntry($map, 'subcategory', 'Toslink', 'Audio');
        }

        if (preg_match('/\b(?:zvucnic\w*|zvucnik\w*)\b/u', $norm)
            && preg_match('/\b(?:kabl\w*|kabel\w*|zic\w*)\b/u', $norm)
        ) {
            return $this->findBucketEntry($map, 'subcategory', 'Kabeli za zvučnike', 'Audio');
        }

        if (!$videoAdapter
            && (
                ($hasAudioCableWord && preg_match('/\b(?:audio|aux|rca|cinc|cinch|jack)\b/u', $norm))
                || $hasAudioConnector
            )
        ) {
            return $this->findBucketEntry($map, 'subcategory', '3.5 mm / RCA', 'Audio');
        }

        if (!$videoAdapter && preg_match('/\b3\s+5(?:\s*mm)?\b/u', $norm)) {
            return $this->findBucketEntry($map, 'subcategory', '3.5 mm / RCA', 'Audio');
        }

        return null;
    }

    /**
     * Remove generic bucket words after a bucket has been identified, keeping
     * useful constraints such as brand/specs.
     *
     * @param string $query
     * @param array  $bucket
     * @return string
     */
    private function queryForBucketSearch($query, array $bucket)
    {
        $norm = Text::normalize($query);
        $name = Text::normalize((string) $bucket['name']);

        if ($name === 'smartphone' || $name === 'mobilni telefoni obicni') {
            $norm = preg_replace('/\b(?:mobitel\w*|smartphone\w*|mobiln\w*|telefon\w*|obicn\w*|tipk\w*|senior)\b/u', ' ', $norm);
        } elseif ($name === 'masina za pranje vesa') {
            // "Perilica (rublja)" is the Croatian term for the same
            // machine and never appears in any product name here (they all
            // say "Mašina za..."), unlike "veš"/"mašina" which genuinely do
            // and must stay as real search anchors - strip only the
            // Croatian synonym, found 2026-08-26 returning zero results.
            $norm = preg_replace('/\b(?:perilic\w*|rublj\w*)\b/u', ' ', $norm);
        } elseif ($name === 'monitori' && Text::normalize((string) $bucket['parent']) === 'pc') {
            // "Zaslon" is the Croatian term for screen/monitor and never
            // appears in a product name here - "monitor" does, and stays.
            $norm = preg_replace('/\bzaslon\w*\b/u', ' ', $norm);
        } elseif ($name === 'printeri i skeneri') {
            // "Pisač" is the Croatian term for printer and never appears in
            // a product name here - "printer" does, and stays.
            $norm = preg_replace('/\bpisac\w*\b/u', ' ', $norm);
        } elseif ($name === 'mikrovalne pecnice') {
            // "Mikrotalasna" is the Serbian (ekavica) term for the same
            // appliance and never appears in a product name here -
            // "mikrovalna" does, and stays.
            $norm = preg_replace('/\bmikrotalasn\w*\b/u', ' ', $norm);
        } elseif ($name === 'ugradbena pecnica') {
            // "Rerna" is the Serbian term for a built-in oven and never
            // appears in a product name here - "pećnica" does, and stays.
            $norm = preg_replace('/\brern\w*\b/u', ' ', $norm);
        } elseif ($name === 'router') {
            // "Ruter" (single r) never appears in a product name here -
            // "Router" does, and stays.
            $norm = preg_replace('/\bruter\w*\b/u', ' ', $norm);
        } elseif ($name === 'elektricni romobili') {
            // "Trotinet" is a common regional term for the same scooter and
            // never appears in a product name here - "romobil" does, and
            // stays.
            $norm = preg_replace('/\btrotinet\w*\b/u', ' ', $norm);
        } elseif ($name === 'klijesta i izvijaci') {
            // This bucket mixes two distinct tools (pliers AND
            // screwdrivers) - unlike the single-product-type buckets above,
            // stripping "šrafciger" to nothing would fall through to a
            // blind category browse that could just as easily return
            // pliers. Replace it with the catalog's own word instead, so
            // the normal token search still narrows to screwdrivers
            // specifically. Also "šarafciger" (extra "a") - found
            // 2026-08-27 that spelling resolved the bucket via its new
            // alias but the word itself wasn't replaced, so it still
            // matched nothing inside the bucket scope.
            $norm = preg_replace('/\bsa?rafciger\w*\b/u', 'odvijac', $norm);
        } elseif ($name === 'blanje pile') {
            // Same reasoning as the pliers/screwdrivers bucket above - this
            // one mixes planers ("blanje") with saws ("pile"), so replace
            // "testera" with the catalog's own word rather than stripping
            // it, or the search could just as easily land on a planer.
            $norm = preg_replace('/\btester\w*\b/u', 'pila', $norm);
        } elseif ($name === 'usb memorija') {
            // "Fleš" (phonetic spelling of "flash") never appears in a
            // product name here - "flash" (English spelling) does, and
            // stays, which is why "flash memorija" already worked via plain
            // token search while "fleš memorija" needed this bucket-alias
            // path in the first place. Strip the bare leftover "memorija"
            // too, not just "fleš": that single generic word, searched
            // alone, ranks RAM sticks first (they are literally named
            // "Memorija DDR4...") - this bucket holds only one product
            // type, so falling through to a plain category browse instead
            // is safe and correct here.
            $norm = preg_replace('/\b(?:fles\w*|memorij\w*)\b/u', ' ', $norm);
        } elseif ($name === 'access point extenderi') {
            // "Pojačivač" and "signal" never appear in a product name here
            // - they are all named "Wireless ... Extender-Access Point...".
            // Strip the bare leftover "wifi" too: alone it is too generic
            // (matches all sorts of WiFi-branded products) - this bucket
            // holds only access points/extenders, so a plain category
            // browse is safe and correct here.
            //
            // Also strip the bare English term "access point" itself -
            // found 2026-08-27: every real product name here starts
            // "Wireless(-N) Extender-Access Point...", so "access"/"point"
            // sit 4th/5th once the hyphens split into words, well past the
            // first-two-words anchor check. Leaving them in as a search
            // token found nothing at all rather than browsing the bucket.
            $norm = preg_replace('/\b(?:pojacivac\w*|signal\w*|wifi|anten\w*|access|point\w*)\b/u', ' ', $norm);
        } elseif ($name === 'strujni razdelnici') {
            // This bucket mixes plain plugs, sockets, adapters and switches
            // with the smart ones - stripping "smart"/"plug" to nothing
            // would fall through to a blind browse that could just as
            // easily return an ordinary non-smart outlet. Replace with the
            // catalog's own word instead, so the search still narrows to
            // "Pametna utičnica..." specifically.
            $norm = preg_replace('/\b(?:smart|plug\w*)\b/u', 'pametna', $norm);
        } elseif ($name === 'cistaci zraka') {
            // This bucket mixes real purifier UNITS ("Pročišćivač zraka...")
            // with FILTERS for them ("Filter za čistač zraka...", "HEPA
            // filter za Mi Air Purifier...") - stripping "prečistač" to
            // nothing would fall through to a blind browse that could just
            // as easily surface a filter instead of the actual appliance.
            // Replace with the catalog's own word instead.
            $norm = preg_replace('/\b(?:precistac\w*|preciscivac\w*)\b/u', 'prociscivac', $norm);
        } elseif ($name === 'soundbar') {
            // "Kućno kino" never appears in a product name here - this
            // bucket holds only soundbars, so a plain category browse is
            // safe and correct.
            $norm = preg_replace('/\b(?:kucn\w*|kino|bioskop|zvucn\w*|sistem\w*)\b/u', ' ', $norm);
        } elseif ($name === 'laptop oprema') {
            // "Sajla" IS the real word ("Zaštitni kabl / sajla za
            // laptop..."), but two separate problems stacked here, found
            // 2026-08-26: (1) "laptop" is common enough across the whole
            // catalog that headToken() picked it over "sajla", so real
            // laptops won instead of the lock cable; stripping it (this
            // bucket is scoped to laptop accessories already, so "laptop"
            // is redundant here) fixed that. But (2) "sajla" is itself only
            // the 3rd/4th word of the real name ("Zaštitni kabl / sajla za
            // laptop..."), same as "konzola" wasn't the 1st word for game
            // consoles - on its own, once "laptop" is gone, it does not
            // lead the name either and still failed nameLeadsWith(). Insert
            // "zastitni kabl" ahead of it, the product's own literal
            // leading words, the same anchor-insertion fix used for the
            // game consoles above.
            $norm = preg_replace('/\bsajl\w*\b/u', 'zastitni kabl sajla', $norm);
            $norm = preg_replace('/\b(?:laptop\w*|prijenosn\w*|racunar\w*)\b/u', ' ', $norm);
        } elseif ($name === 'kabeli napojni') {
            // This bucket holds power cords for several different devices
            // (PC, stednjak/stove, laptop) - unlike most single-purpose
            // buckets, stripping "laptop" here would leave a blind browse of
            // all of them, showing the stove cords too. And "laptop" alone
            // is not enough to anchor on its own: the real product is named
            // "Kabl napojni za laptop..." - "laptop" is only the 4th word,
            // so nameLeadsWith() would not accept it as a match by itself
            // (same problem as "sajla za laptop" above). Strip the
            // customer's own "kabl/kabel" word (already implied by the
            // bucket), then insert the product's own leading words ahead of
            // "laptop" the same way.
            $norm = preg_replace('/\b(?:kabl\w*|kabel\w*)\b/u', ' ', $norm);
            $norm = preg_replace('/\blaptop\w*\b/u', 'kabl napojni laptop', $norm);
        } elseif ($name === 'kartice i ci moduli') {
            // Small bucket (2 products: a card reader, a CAM module) -
            // strip the customer's own wording and let a blind browse show
            // both. Found 2026-08-27.
            $norm = preg_replace('/\b(?:smart|kartic\w*|dekoder\w*|dekodiranj\w*|kanal\w*|ci|modul\w*|citac\w*)\b/u', ' ', $norm);
        } elseif ($name === 'elektronske brave') {
            // Real products are "Elektronska sigurnosna brava..."/"Elektro
            // prihvatnik za vrata..." - strip the customer's natural
            // "električna brava"/"daljinska brava" wording and let a blind
            // browse show the real access-control stock. Found 2026-08-27.
            $norm = preg_replace('/\b(?:elektricn\w*|elektronsk\w*|brav\w*|daljinsk\w*|za|vrata)\b/u', ' ', $norm);
        } elseif ($name === 'cd dvd') {
            // "cd" is only 2 characters - too short for InnoDB's fulltext
            // index, and "disk"/"prazan" never appear in a real product name
            // here ("CD-R 700MB...", "DVD-R 4,7GB..."). Strip all of it and
            // let a blind browse show the real CD-R/DVD-R stock instead.
            // Found 2026-08-27.
            $norm = preg_replace('/\b(?:cd|dvd|disk\w*|prazan|prazni|prazna)\b/u', ' ', $norm);
        } elseif ($name === 'neonke') {
            // Single-product-type bucket - strip the synonym so it falls to
            // a blind browse of the real "Sijalica, LED cijev..." items.
            // Found 2026-08-27.
            $norm = preg_replace('/\b(?:fluorescentn\w*|fluo|cijev\w*)\b/u', ' ', $norm);
        } elseif ($name === 'timeri') {
            // Single-product-type bucket ("Programator vremena...") - strip
            // the colloquial "tajmer" and its qualifiers. Found 2026-08-27.
            $norm = preg_replace('/\b(?:tajmer\w*|struj\w*|utikac\w*|prekidac\w*)\b/u', ' ', $norm);
        } elseif ($name === 'pretvaraci napona') {
            // Mixes DC/AC inverters ("Adapter 12V na 220V...") with voltage
            // stabilizers ("Stabilizator napona...") - close enough related
            // devices that a blind browse showing both under "inverter" is
            // reasonable (no cleaner split exists in this catalog). Found
            // 2026-08-27.
            $norm = preg_replace('/\b(?:inverter\w*|invertor\w*)\b/u', ' ', $norm);
        } elseif ($name === 'mjerni instrumenti') {
            // "Multimetar" etc. sit well past the first two words of the
            // real product name ("Instrumet mjerni, digitalni multimetar" -
            // note the catalog's own typo, "Instrumet") - strip them and let
            // a blind browse show the (few) real instruments here instead.
            // Found 2026-08-27.
            $norm = preg_replace('/\b(?:multimetar\w*|ampermetar\w*|voltmetar\w*)\b/u', ' ', $norm);
        } elseif ($name === 'elektricni kamini') {
            // Bare "kamin" already anchors fine on its own (single-product-
            // type bucket, real products are "Kamin, električni...") - just
            // strip the qualifier "na struju"/"struju" a customer naturally
            // adds, which otherwise leaves a leftover word with no anchor
            // role of its own. Found 2026-08-27.
            $norm = preg_replace('/\b(?:na|struj\w*)\b/u', ' ', $norm);
        } elseif ($name === 'zamke za insekte glodare') {
            // Mixes insect AND rodent traps/repellers, so a blind browse
            // would show insect zappers under a mouse-trap request - replace
            // the colloquial "klopka" with the catalog's own word "zamka"
            // instead, so the remaining word ("miševe"/"pacove") still
            // narrows to the right kind. Found 2026-08-27.
            $norm = preg_replace('/\bklopk\w*\b/u', 'zamka', $norm);
        } elseif ($name === 'futrole etui za tablet') {
            // Mixed bucket (Futrola/Etui/Maska all appear here) - replace
            // "navlaka" (not a real word in any product name here) with the
            // catalog's own "futrola" so the anchor still succeeds. Found
            // 2026-08-27.
            $norm = preg_replace('/\bnavlak\w*\b/u', 'futrola', $norm);
        } elseif ($name === 'ebike') {
            // Single-product bucket (one real e-bike in stock today), so a
            // blind browse is safe. Needed because the real product name has
            // a typo in the catalog feed itself - "Elekrični Gradski
            // bicikl..." (missing the "t" "Električni" would have) - so
            // "elektricni" (correctly spelled) is not even a name_text
            // prefix match for it, and "bicikl" sits 3rd word regardless.
            // Found 2026-08-27.
            $norm = preg_replace('/\b(?:bicikl\w*|elektricn\w*|gradsk\w*)\b/u', ' ', $norm);
        } elseif ($name === 'video nadzor ip') {
            // A whole category (cameras, recorders, cabling...), so a
            // generic "video nadzor"/"sistem video nadzora" (no specific
            // product word) is a real category-browse request, not a
            // narrow product search - strip the bucket's own name words so
            // it falls to a blind browse instead of trying to anchor on
            // "video"/"nadzor", which sit well past the first two words of
            // every real product name here ("Kamera za video nadzor...").
            $norm = preg_replace('/\b(?:video|nadzor\w*|sistem\w*)\b/u', ' ', $norm);
        } elseif ($name === 'selfie stick drzac') {
            // This bucket mixes two distinct product types (selfie sticks
            // and phone/tablet holders/stands), so it cannot be a blind
            // browse. "Stalak"/"postolje" (stand) never appears in a real
            // product name here - they are all "Držač za..." - replace it
            // with the literal word so the search actually anchors on the
            // holder products instead of matching nothing. Found
            // 2026-08-27 alongside the routing fix above (same bucket).
            $norm = preg_replace('/\b(?:stalak|stalci|stalka|postolje|postolja)\b/u', 'drzac', $norm);
        } elseif ($name === 'software') {
            // Same head-selection problem: headToken() picked "program"
            // over "antivirus", and no real product name contains "program"
            // at all (they are named "eScan Anti-Virus...", "Windows 10
            // Home OEM", etc.) - strip the generic filler word and leave
            // the real product word as the anchor.
            $norm = preg_replace('/\b(?:program\w*|softver\w*|software)\b/u', ' ', $norm);
        } elseif ($name === 'brusilice') {
            // This bucket mixes angle, orbital and bench grinders/sanders -
            // replace the Serbian "ugaona" with the catalog's own "kutna"
            // instead of stripping it, so the search still narrows to
            // angle grinders specifically rather than any grinder in stock.
            $norm = preg_replace('/\bugaon\w*\b/u', 'kutna', $norm);
        } elseif ($name === 'igrace konzole') {
            // Every real product here is named "Igraća konzola BRAND
            // MODEL..." - "konzola" is always word 2, but the brand
            // ("PlayStation 5", "X Box", "Nintendo Switch") is always word
            // 3+, so it can never satisfy nameLeadsWith() on its own no
            // matter how it's spelled. Insert "konzola" ahead of the brand
            // instead of stripping anything: "konzola" alone satisfies the
            // lead-word anchor, and the brand word(s) still have to be
            // literally present too (fulltextSearch's Pass 1 requires every
            // token), so this cannot drift onto an unrelated console.
            // Normalize spelling gaps in the same pass - "ps5"/"plejstejsn
            // 5" never appear literally (real name spells out
            // "PlayStation 5"), and "xbox" (no space) never appears either
            // (real name has a space, "X Box"). Found 2026-08-26: none of
            // "ps5"/"xbox"/"playstation 5"/"nintendo switch" found the real
            // consoles in stock.
            $norm = preg_replace('/\b(?:ps\s*5|plejstejsn\s*5)\b/u', 'konzola playstation 5', $norm);
            $norm = preg_replace('/\bplaystation\s*5\b/u', 'konzola playstation 5', $norm);
            $norm = preg_replace('/\bxbox\b/u', 'konzola x box', $norm);
            $norm = preg_replace('/\bx\s+box\b/u', 'konzola x box', $norm);
            $norm = preg_replace('/\bnintendo\s+switch\b/u', 'konzola nintendo switch', $norm);
            $norm = trim(preg_replace('/\bkonzola\s+konzola\b/u', 'konzola', $norm));
        } elseif ($name === 'slavine i tus baterije') {
            // "Tuš baterija" IS a literal phrase in several real product
            // names here - both the fixture itself and its accessories -
            // so a plain category browse is a reasonable answer either way.
            // The bug this fixes is not the phrase itself but "baterija"
            // alone: outside this bucket it is one of the most common words
            // in the whole catalog (power-tool batteries), so bare "tuš
            // baterija" was losing the head-word contest to those instead.
            $norm = preg_replace('/\b(?:tus|baterij\w*)\b/u', ' ', $norm);
        } elseif ($name === 'e cigarete') {
            // "Vejp"/"vape" never appear in a product name here - they are
            // all named "Cigareta elektronska...".
            $norm = preg_replace('/\b(?:vejp\w*|vape\w*|vaper\w*)\b/u', ' ', $norm);
        } elseif ($name === 'maska za iphone') {
            // "Ajfon" is a phonetic spelling that never appears in a
            // product name here - "iPhone" (English spelling) does.
            $norm = preg_replace('/\bajfon\w*\b/u', ' ', $norm);
        } elseif ($name === 'novogodisnji program') {
            // "Novogodišnje lampice"/"lampice za jelku" never appear in a
            // product name here - real products are all named "Dekorativna
            // LED rasvjeta".
            $norm = preg_replace('/\b(?:novogodisnj\w*|lampic\w*|jelk\w*|bozicn\w*)\b/u', ' ', $norm);
        } elseif ($name === 'smartwatch') {
            // "Narukvica" (bracelet) never appears in a real smartwatch's
            // name here. "Fitnes"/"fitness" is a closer call - some real
            // models do say "fitness" - but the single-s Bosnian spelling
            // "fitnes" does not resolveToken()-match the double-s English
            // spelling in those names, so it was still failing as a
            // leftover token. Strip both: this bucket holds only
            // smartwatches, so a plain category browse is safe here.
            $norm = preg_replace('/\b(?:narukvic\w*|fitnes\w*|fitness\w*)\b/u', ' ', $norm);
        } elseif ($name === 'fotoaparati kamere') {
            $norm = preg_replace('/\b(?:foto|aparat\w*|fotoaparat\w*)\b/u', ' ', $norm);
        } elseif ($name === 'kablovi') {
            $norm = preg_replace('/\b(?:kabl\w*|kabel\w*)\b/u', ' ', $norm);
        } elseif ($name === 'hdmi') {
            $norm = preg_replace('/\b(?:hdmi|kabl\w*|kabel\w*)\b/u', ' ', $norm);
            $norm = $this->stripCableLengthWords($norm);
        } elseif ($name === 'hdmi adapteri') {
            $norm = preg_replace('/\b(?:hdmi|adapter\w*|konektor\w*|konverter\w*|prelaz\w*|nastavak\w*)\b/u', ' ', $norm);
        } elseif ($name === 'hdmi razdjelnici') {
            $norm = preg_replace('/\b(?:hdmi|razdjelnik\w*|razdelnik\w*|splitter\w*)\b/u', ' ', $norm);
        } elseif ($name === 'scart') {
            // "konektor" added 2026-08-27: "konektor scart" resolves this
            // bucket via its own new alias, but real products here are all
            // "Scart kabl..." - never "konektor" - so the leftover word
            // still blocked the anchor match.
            $norm = preg_replace('/\b(?:scart|kabl\w*|kabel\w*|konektor\w*)\b/u', ' ', $norm);
            $norm = $this->stripCableLengthWords($norm);
        } elseif ($name === 'scart rca') {
            $norm = preg_replace('/\b(?:scart|rca|cinc|cinch|prelaz\w*|kabl\w*|kabel\w*)\b/u', ' ', $norm);
            $norm = $this->stripCableLengthWords($norm);
        } elseif ($name === 's vhs') {
            $norm = preg_replace('/\b(?:s|vhs|svhs|kabl\w*|kabel\w*)\b/u', ' ', $norm);
            $norm = $this->stripCableLengthWords($norm);
        } elseif ($name === 'display port') {
            $norm = preg_replace('/\b(?:display|port|displayport|dp|kabl\w*|kabel\w*)\b/u', ' ', $norm);
            $norm = $this->stripCableLengthWords($norm);
        } elseif ($name === 'vga dvi') {
            $norm = preg_replace('/\b(?:vga|dvi|kabl\w*|kabel\w*|adapter\w*|konektor\w*)\b/u', ' ', $norm);
            $norm = $this->stripCableLengthWords($norm);
        } elseif ($name === 'usb produzni kabel') {
            $norm = preg_replace('/\b(?:usb|type|c|produzn\w*|produžn\w*|produzet\w*|produžet\w*|extension|hub|kabl\w*|kabel\w*)\b/u', ' ', $norm);
            $norm = $this->stripCableLengthWords($norm);
        } elseif ($name === 'usb kabel za printer') {
            $norm = preg_replace('/\b(?:usb|printer\w*|stampac\w*|stampač\w*|kabl\w*|kabel\w*)\b/u', ' ', $norm);
            $norm = $this->stripCableLengthWords($norm);
        } elseif ($name === 'patch kablovi') {
            $norm = preg_replace('/\b(?:lan|ethernet|internet|mrezn\w*|mrežn\w*|utp|ftp|rj45|patch|kabl\w*|kabel\w*)\b/u', ' ', $norm);
        } elseif ($name === 'mrezni kabel na metar') {
            $norm = preg_replace('/\b(?:lan|ethernet|internet|mrezn\w*|mrežn\w*|utp|ftp|rj45|kabl\w*|kabel\w*|na|metar|metre|metri)\b/u', ' ', $norm);
        } elseif ($name === 'kabeli koaksijalni') {
            $norm = preg_replace('/\b(?:koaksijaln\w*|coax\w*|antensk\w*|tv|sat|kablovsk\w*|kabelsk\w*|kabl\w*|kabel\w*)\b/u', ' ', $norm);
        } elseif ($name === 'razdjelnici' && Text::normalize((string) $bucket['parent']) === 'tv sat') {
            $norm = preg_replace('/\b(?:razdjelnik\w*|razdelnik\w*|splitter\w*|antensk\w*|tv|sat|kablovsk\w*|kabelsk\w*)\b/u', ' ', $norm);
        } elseif ($name === 'odcjepnici za kabelsku') {
            $norm = preg_replace('/\b(?:odcjepnik\w*|odcepnik\w*|kablovsk\w*|kabelsk\w*)\b/u', ' ', $norm);
        } elseif ($name === 'telefonski kabel') {
            $norm = preg_replace('/\b(?:telefonsk\w*|telefonij\w*|telefon\w*|rj11|dsl|kabl\w*|kabel\w*)\b/u', ' ', $norm);
            $norm = $this->stripCableLengthWords($norm);
        } elseif ($name === 'telefonski kabeli na metar') {
            $norm = preg_replace('/\b(?:telefonsk\w*|telefonij\w*|telefon\w*|rj11|dsl|kabl\w*|kabel\w*|na|metar|metre|metri)\b/u', ' ', $norm);
        } elseif ($name === 'aparati za brijanje') {
            // "brijac" (razor) does not share a fulltext prefix with "brijanje"
            // (the word every real product name uses), so a literal-match
            // search would find only the one accessory that happens to start
            // with "brijac". Strip it and let the browse+preference ranking
            // below pick the real razors.
            $norm = preg_replace('/\b(?:brijac\w*|brijanj\w*|aparat\w*)\b/u', ' ', $norm);
        } elseif ($name === 'alarm') {
            // No single component literally says "alarm sistem"/"za kucu" -
            // these words must not stay required, or the only "match" left
            // is an unrelated car/clock alarm that happens to say "alarm".
            $norm = preg_replace('/\b(?:alarm\w*|sistem\w*|kucu|kucni\w*)\b/u', ' ', $norm);
        } elseif ($name === 'jablotron ja 100') {
            $norm = preg_replace('/\b(?:jablotron\w*|alarm\w*)\b/u', ' ', $norm);
        } elseif ($name === 'cistaci zraka') {
            // "procistivac"/"prociscivac" (however the customer spells it)
            // does not literally prefix-match "pročišćivač"/"čistač" in the
            // product names, so it must not stay a required search word.
            $norm = preg_replace('/\b(?:pro)?cist\w*\b/u', ' ', $norm);
        } elseif ($name === 'timeri') {
            // Only half these products say "timer" - the rest say
            // "programator vremena" - and none say "struja", the word that
            // got the customer here in the first place. Strip both so the
            // leftover query browses the whole bucket instead of excluding
            // whichever half does not match literally.
            $norm = preg_replace('/\b(?:timer\w*|struj\w*)\b/u', ' ', $norm);
        } elseif ($name === 'desktop aio racunari') {
            // Products say "Desktop PC" / "Desktop AiO", never "računar" (or
            // its Croatian equivalent "računalo", or the slang "kompić") -
            // the word the customer actually used to reach this bucket.
            // "kompic" also protects against a stemming collision found
            // 2026-08-25: resolveToken() shortens it toward "komp", which
            // coincidentally prefix-matches unrelated products like
            // "Komplet za čišćenje espresso aparata" once it falls through
            // to the token-based fallback search - stripping it here means
            // that fallback is never reached for this word at all.
            $norm = preg_replace('/\b(?:racunar\w*|kompjuter\w*|racunal\w*|kompic\w*)\b/u', ' ', $norm);
        } elseif ($name === 'kontroleri volani') {
            // Products say "Gamepad", never "kontroler" - keep the platform
            // word (ps5/ps4/xbox/pc) so the browse still narrows down, but
            // drop the word that would never literal-match anything.
            $norm = preg_replace('/\bkontroler\w*\b/u', ' ', $norm);
        } elseif ($name === 'pegle glacala') {
            // Products are named "Pegla...", never "Glačalo...", so the
            // synonym must not stay as a required literal-match word.
            $norm = preg_replace('/\b(?:pegl\w*|glacal\w*)\b/u', ' ', $norm);
        } elseif ($name === 'kabel za slusalicu') {
            $norm = preg_replace('/\b(?:kabl\w*|kabel\w*|slusalic\w*)\b/u', ' ', $norm);
        } elseif ($name === 'audio professional') {
            $norm = preg_replace('/\b(?:audio|profesionaln\w*|professional)\b/u', ' ', $norm);
        } elseif ($name === '110v pojacalo i zvucnici') {
            $norm = preg_replace('/\b(?:audio|profesionaln\w*|professional|110v|razglas|ozvucenj\w*)\b/u', ' ', $norm);
        } elseif ($name === 'zvucnici' && Text::normalize((string) $bucket['parent']) === 'audio professional') {
            $norm = preg_replace('/\b(?:audio|profesionaln\w*|professional|zvucnik\w*|zvucnic\w*)\b/u', ' ', $norm);
        } elseif ($name === 'mikrofoni' && Text::normalize((string) $bucket['parent']) === 'audio professional') {
            $norm = preg_replace('/\b(?:audio|profesionaln\w*|professional|mikrofon\w*)\b/u', ' ', $norm);
        } elseif ($name === 'dijelovi za zvucnike') {
            $norm = preg_replace('/\b(?:dijel\w*|del\w*|zvucnik\w*|zvucnic\w*)\b/u', ' ', $norm);
        } elseif ($name === 'mikro prekidaci') {
            $norm = preg_replace('/\b(?:mikro|prekidac\w*|mikroprekidac\w*)\b/u', ' ', $norm);
        } elseif ($name === 'konektori' && Text::normalize((string) $bucket['parent']) === 'audio professional') {
            $norm = preg_replace('/\b(?:audio|profesionaln\w*|professional|konektor\w*)\b/u', ' ', $norm);
        } elseif ($name === '3 5 mm rca') {
            $norm = preg_replace('/\b(?:audio|aux|rca|cinc|cinch|jack|kabl\w*|kabel\w*)\b/u', ' ', $norm);
            $norm = preg_replace('/\b3\s+5(?:\s*mm)?\b/u', ' ', $norm);
            $norm = preg_replace('/\bmm\b/u', ' ', $norm);
        } elseif ($name === 'toslink') {
            $norm = preg_replace('/\b(?:toslink|optick\w*|audio|kabl\w*|kabel\w*)\b/u', ' ', $norm);
        } elseif ($name === 'kabeli za zvucnike') {
            $norm = preg_replace('/\b(?:kabl\w*|kabel\w*|zvucnik\w*|zvucnic\w*|zic\w*)\b/u', ' ', $norm);
        } elseif ($name === 'adapteri i konektori') {
            $parent = Text::normalize((string) $bucket['parent']);
            if ($parent === 'audio') {
                $norm = preg_replace('/\b(?:adapter\w*|konektor\w*|audio|slusalic\w*)\b/u', ' ', $norm);
            } elseif ($parent === 'network') {
                $norm = preg_replace('/\b(?:adapter\w*|konektor\w*|network|lan|ethernet|internet|mrezn\w*|mrežn\w*|utp|ftp|rj45|patch)\b/u', ' ', $norm);
            } elseif ($parent === 'tv sat') {
                $norm = preg_replace('/\b(?:adapter\w*|konektor\w*|tv|sat|antensk\w*|koaksijaln\w*|coax\w*|kablovsk\w*|kabelsk\w*)\b/u', ' ', $norm);
            } elseif ($parent === 'telefonija') {
                $norm = preg_replace('/\b(?:adapter\w*|konektor\w*|telefonij\w*|telefonsk\w*|telefon\w*|rj11|dsl)\b/u', ' ', $norm);
            }
        } elseif (in_array($name, ['maska za smartphone', 'maska za iphone'], true)) {
            $norm = preg_replace('/\b(?:maska|maske|masku|mobitel\w*|smartphone\w*|telefon\w*|iphone|apple)\b/u', ' ', $norm);
        } elseif (in_array($name, ['futrole etui za tablet', 'futrole flip iphone', 'futrole flip smartphone'], true)) {
            $norm = preg_replace('/\b(?:futrola|futrole|futrolu|etui|flip|tablet\w*|ipad|tab|mobitel\w*|smartphone\w*|telefon\w*|iphone|apple)\b/u', ' ', $norm);
        } elseif (in_array($name, ['zastita za ekran smartphone', 'zastita za ekran iphone'], true)) {
            $norm = preg_replace('/\b(?:zastita|zastite|zastitn\w*|ekran\w*|staklo|folija|folije|mobitel\w*|smartphone\w*|telefon\w*|iphone|apple)\b/u', ' ', $norm);
        } elseif ($name === 'kabeli za mobitel tablet') {
            // Unlike the mask/case/screen-protector buckets above, there is no
            // separate "Kabeli za iPhone" bucket - Lightning, USB-C and micro
            // USB cables all live in this one category, so "iphone"/"apple"
            // is the only signal telling them apart. Keep it as a required
            // word instead of stripping it: the real Lightning cables do say
            // "iPhone" in their name, so a literal match finds them, and
            // dropping the word here was falling through to a bucket-wide
            // browse that could just as easily surface a micro USB cable
            // that does not fit an iPhone at all.
            $norm = preg_replace('/\b(?:kabl|kabel|kabeli|kabli|mobitel\w*|smartphone\w*|telefon\w*|tablet\w*|ipad)\b/u', ' ', $norm);
        } elseif (in_array($name, ['punjac kucni', 'punjac auto', 'kabl za pametni sat', 'remen za pametni sat'], true)) {
            // "punjac"/"kućni" are NOT redundant filler for this bucket the
            // way "telefon"/"smartphone" are for the accessory buckets
            // above - real products are literally named "Punjač kućni,
            // brzi..." (punjač = 1st word, kućni = 2nd), so they are the
            // actual name-anchor. Stripping them left a real, meaningful
            // modifier like "brzi" (fast - genuinely splits this bucket
            // roughly in half) as the ONLY remaining token, which does not
            // itself lead the name (it is the 3rd word) and so matched
            // nothing at all. Found 2026-08-26: "punjač brzi" returned zero
            // results despite 44 real fast chargers in stock. Keep them;
            // fulltextSearch's own loosening passes already handle a
            // genuinely non-matching modifier gracefully.
            $norm = preg_replace('/\b(?:auto|auta|autu|automobil\w*|kabl|kabel|remen|pametn\w*|sat\w*|smartwatch|smart|watch|mobitel\w*|smartphone\w*|telefon\w*|android\w*|ios|iphone|apple)\b/u', ' ', $norm);
        }

        return trim(preg_replace('/\s+/u', ' ', $norm));
    }

    /**
     * Lengths such as "2m" or "1.5 met" are useful details for a human, but
     * they often do not tokenize the same way as the catalog. Avoid letting a
     * length-only remainder turn an otherwise correct bucket match into "no
     * results".
     *
     * @param string $norm Already normalised text.
     * @return string
     */
    private function stripCableLengthWords($norm)
    {
        $norm = preg_replace('/\b\d+\s*(?:m|met|metar|metra|metara)\b/u', ' ', $norm);
        $norm = preg_replace('/\b\d+\s+\d+\s*(?:m|met|metar|metra|metara)\b/u', ' ', $norm);
        $norm = preg_replace('/\b(?:met|metar|metra|metara)\b/u', ' ', $norm);

        return trim(preg_replace('/\s+/u', ' ', $norm));
    }

    /**
     * @param string $text
     * @return float|null
     */
    private function extractCableLengthMeters($text)
    {
        $norm = Text::normalize($text);
        if (!preg_match('/\b(?:kabl\w*|kabel\w*|hdmi|scart|vga|dvi|display\s*port|displayport|usb|lan|ethernet|patch|mrezn\w*|mrežn\w*|koaksijaln\w*|antensk\w*|toslink|rca|cinc|cinch)\b/u', $norm)) {
            return null;
        }

        $raw = mb_strtolower((string) $text, 'UTF-8');
        $raw = str_replace(',', '.', $raw);

        if (preg_match('/\b(\d+(?:\.\d+)?)\s*(?:m|met|metar|metra|metara)\b/u', $raw, $m)) {
            return (float) $m[1];
        }

        if (preg_match('/\b(\d+)\s+(\d+)\s*(?:m|met|metar|metra|metara)\b/u', $norm, $m)) {
            return (float) ($m[1] . '.' . $m[2]);
        }

        return null;
    }

    /**
     * Washing machine spin speed ("1400 obrtaja"). Unlike cable length this
     * is a real constraint, not a soft preference: "preko 1200 obrtaja"
     * means at least 1200 RPM, not "closest to 1200". Returns min/max/target
     * plus the query with the spin-speed words removed, since the exact
     * digit ("1200") must not stay a required search word - a 1400 RPM
     * machine would then wrongly fail to match "at least 1200".
     *
     * @param string $text
     * @return array{min: int|null, max: int|null, target: int|null, query: string}|null
     */
    private function extractSpinSpeedRpm($text)
    {
        $norm = Text::normalize($text);
        if (!preg_match('/\bobrtaj\w*\b/u', $norm)) {
            return null;
        }

        if (!preg_match('/\b(\d{3,4})\s*obrtaj\w*\b/u', $norm, $m)) {
            return null;
        }

        $rpm = (int) $m[1];
        $min = null;
        $max = null;

        $before = mb_substr($norm, 0, mb_strpos($norm, $m[0]));

        if (preg_match('/\b(?:preko|iznad|najmanje|vise\s+od|min(?:imalno)?)\s*$/u', $before)) {
            $min = $rpm;
        } elseif (preg_match('/\b(?:do|ispod|manje\s+od|max(?:imalno)?)\s*$/u', $before)) {
            $max = $rpm;
        }

        $stripped = preg_replace(
            '/\b(?:preko|iznad|najmanje|vise\s+od|min(?:imalno)?|do|ispod|manje\s+od|max(?:imalno)?)\s+(\d{3,4})\s*obrtaj\w*\b|\b(\d{3,4})\s*obrtaj\w*\b/u',
            ' ',
            $norm
        );

        return [
            'min'    => $min,
            'max'    => $max,
            'target' => ($min === null && $max === null) ? $rpm : null,
            'query'  => trim(preg_replace('/\s+/u', ' ', (string) $stripped)),
        ];
    }

    /**
     * Pull the spin speed out of a product's own name ("Mašina za veš, 1400
     * obrtaja, ...") - unlike extractSpinSpeedRpm this reads a result we
     * already have, not the customer's sentence, so it does not need the
     * "preko/do" direction words.
     *
     * @param string $name
     * @return int|null
     */
    private function spinSpeedFromName($name)
    {
        if (preg_match('/\b(\d{3,4})\s*obrtaj\w*\b/u', Text::normalize($name), $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * @param array[]  $results
     * @param int|null $min
     * @param int|null $max
     * @return array[]
     */
    private function filterBySpinSpeed(array $results, $min, $max)
    {
        return array_values(array_filter($results, function ($row) use ($min, $max) {
            $rpm = $this->spinSpeedFromName(isset($row['name']) ? $row['name'] : '');
            if ($rpm === null) {
                return false;
            }
            if ($min !== null && $rpm < $min) {
                return false;
            }
            if ($max !== null && $rpm > $max) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Exact bucket names are browsing requests, not product-name searches.
     * Example: products inside "Prijenosni uređaji" usually do not contain the
     * words "prijenosni uređaji" in their own names.
     *
     * @param string $query
     * @param array  $bucket
     * @return bool
     */
    private function isExactBucketNameQuery($query, array $bucket)
    {
        return $this->bucketKey($query) === $this->bucketKey((string) $bucket['name']);
    }

    /**
     * Brand names present in stock, normalised, longest name first so a
     * multi-word brand ("A4Tech") is tried before a shorter one that could
     * otherwise match part of it.
     *
     * @return array<int,array{norm: string, id: int, name: string}>
     */
    private function brandMap()
    {
        if ($this->brandCache !== null) {
            return $this->brandCache;
        }

        $sql = 'SELECT DISTINCT b.id, b.name
                FROM brands b
                JOIN products p ON p.brand_id = b.id
                WHERE p.stock > 0 AND b.name <> ""';

        // Short brand names that collide with ordinary words ("DC" as in
        // direct current, "HQ", plain English "home"/"save"/"true"/"use") -
        // treating these as a brand filter would misfire far more often than
        // it would correctly catch a real brand mention.
        $blocklist = ['dc', 'gp', 'hq', 'ms', 'nn', 'ea', 'use', 'home', 'save', 'true'];

        $map = [];
        foreach ($this->pdo->query($sql)->fetchAll() as $row) {
            $norm = Text::normalize((string) $row['name']);
            if ($norm === '' || in_array($norm, $blocklist, true)) {
                continue;
            }
            $map[] = ['norm' => $norm, 'id' => (int) $row['id'], 'name' => (string) $row['name']];
        }

        usort($map, function ($a, $b) {
            return mb_strlen($b['norm']) <=> mb_strlen($a['norm']);
        });

        $this->brandCache = $map;

        return $map;
    }

    /**
     * Find a brand name mentioned in the query and strip it out, so it can be
     * applied as a real brand_id filter instead of relying on the brand name
     * happening to also appear in the product's indexed text.
     *
     * @param string $query
     * @return array{id: int, name: string, query: string}|null
     */
    private function extractBrand($query)
    {
        $norm = Text::normalize($query);
        if ($norm === '') {
            return null;
        }

        foreach ($this->brandMap() as $brand) {
            if (mb_strlen($brand['norm']) < 2) {
                continue;
            }
            if (!preg_match('/\b' . preg_quote($brand['norm'], '/') . '\b/u', $norm)) {
                continue;
            }

            $stripped = preg_replace('/\b' . preg_quote($brand['norm'], '/') . '\b/u', ' ', $norm);

            return [
                'id'    => $brand['id'],
                'name'  => $brand['name'],
                'query' => trim(preg_replace('/\s+/u', ' ', (string) $stripped)),
            ];
        }

        return null;
    }

    /**
     * Build a link to the matching category/brand listing on the live shop,
     * for a "Prikaži više" link under a set of results.
     *
     * Grounded in the products actually shown, not a fresh re-parse of the
     * query text: most searches ("frižider", "štampač", "bušilica") are
     * found by search() through plain fulltext ranking with no named bucket
     * alias at all, so re-deriving a category from the query text alone
     * would miss most of them. Reading it off the results themselves works
     * for every case search() can find, and can never disagree with what is
     * actually on screen.
     *
     * digitalis.ba's category/subcategory/brand ids are the same ids as our
     * own categories/subcategories/brands tables (same feed), confirmed by
     * hand against the live site, so the ids read here can be dropped
     * straight into its URL with no separate mapping table. zed.hr and
     * optibox.rs run the same "webshop" listing route. Dstore uses SEO paths
     * for listings, so its "Prikaži više" link is built from the category and,
     * when all visible products share it, the brand name.
     *
     * Price ordering can be carried as URL intent. If a shop ignores unknown
     * query params the link still opens the same listing; if it supports one
     * of these sort keys, it can immediately show the requested order.
     *
     * @param array[] $results Shaped rows as returned by search() (need 'id').
     * @param string|null $sort price_asc, price_desc or discount_desc.
     * @return string|null
     */
    public function shopListingUrlForResults(array $results, $sort = null)
    {
        $ids = [];
        foreach ($results as $row) {
            if (isset($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }
        if ($ids === []) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, category_id, subcategory_id, brand_id FROM products WHERE id IN ({$placeholders})"
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return null;
        }

        // The top (most relevant) visible result decides category/subcategory.
        // The DB query below only fills id-based filters for webshop stores;
        // it must not decide the visible result order.
        $firstResult = $results[0];
        $categoryId = isset($firstResult['category_id']) ? (int) $firstResult['category_id'] : null;
        $subcategoryId = isset($firstResult['subcategory_id']) && $firstResult['subcategory_id'] !== null
            ? (int) $firstResult['subcategory_id']
            : null;
        $brandId = isset($firstResult['brand_id']) ? (int) $firstResult['brand_id'] : null;

        foreach ($rows as $row) {
            if ((int) $row['id'] !== (int) $firstResult['id']) {
                continue;
            }
            $categoryId = isset($row['category_id']) ? (int) $row['category_id'] : null;
            $subcategoryId = isset($row['subcategory_id']) && $row['subcategory_id'] !== null
                ? (int) $row['subcategory_id']
                : null;
            $brandId = isset($row['brand_id']) ? (int) $row['brand_id'] : null;
            break;
        }

        foreach ($rows as $row) {
            $rowBrandId = isset($row['brand_id']) ? (int) $row['brand_id'] : null;
            if ($rowBrandId !== $brandId) {
                $brandId = null;
                break;
            }
        }

        if ($categoryId === null && $brandId === null) {
            return null;
        }

        $params = [];
        if ($categoryId !== null) {
            $params['cid'] = $categoryId;
        }
        if ($subcategoryId !== null) {
            $params['pid'] = $subcategoryId;
        }
        if ($brandId !== null) {
            $params['bid'] = $brandId;
        }

        $shopBase = rtrim((string) config_get('shop_base_url', 'https://www.digitalis.ba'), '/');
        $style = (string) config_get('shop_url_style', 'webshop');

        if ($style === 'flat') {
            $categoryName = isset($firstResult['category']) ? trim((string) $firstResult['category']) : '';
            $subcategoryName = isset($firstResult['subcategory']) ? trim((string) $firstResult['subcategory']) : '';

            if ($categoryName !== '' && $subcategoryName !== '') {
                return $this->withListingSort($shopBase . '/' . self::dstorePathSegment($categoryName . '-' . $subcategoryName), $sort);
            }
            if ($categoryName !== '') {
                return $this->withListingSort($shopBase . '/' . self::dstorePathSegment($categoryName), $sort);
            }
            if ($brandId !== null && isset($firstResult['brand']) && trim((string) $firstResult['brand']) !== '') {
                return $this->withListingSort($shopBase . '/' . self::dstoreLabelSegment((string) $firstResult['brand']), $sort);
            }

            return isset($firstResult['url']) && $firstResult['url'] !== ''
                ? $this->withListingSort((string) $firstResult['url'], $sort)
                : null;
        }

        return $this->withListingSort($shopBase . '/webshop/proizvodi/?' . http_build_query($params), $sort);
    }

    /**
     * @param string      $url
     * @param string|null $sort
     * @return string
     */
    private function withListingSort($url, $sort)
    {
        if (!in_array($sort, ['price_asc', 'price_desc', 'discount_desc'], true)) {
            return $url;
        }

        $sortParams = [
            'sort'  => $sort,
            'order' => $sort,
        ];

        if ($sort === 'price_asc') {
            $sortParams['orderby'] = 'price';
            $sortParams['dir'] = 'asc';
        } elseif ($sort === 'price_desc') {
            $sortParams['orderby'] = 'price';
            $sortParams['dir'] = 'desc';
        } elseif ($sort === 'discount_desc') {
            $sortParams['orderby'] = 'discount';
            $sortParams['dir'] = 'desc';
        }

        return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($sortParams);
    }

    /**
     * @return array<string,array[]>
     */
    private function bucketMap()
    {
        if ($this->bucketCache !== null) {
            return $this->bucketCache;
        }

        $map = [];

        $sql = 'SELECT "supercategory" AS type, sg.id, sg.name, NULL AS parent, sg.id AS super_id
                FROM supercategories sg
                JOIN categories c ON c.super_category_id = sg.id
                JOIN products p ON p.category_id = c.id
                WHERE p.stock > 0
                GROUP BY sg.id, sg.name
                UNION ALL
                SELECT "category" AS type, c.id, c.name, sg.name AS parent, sg.id AS super_id
                FROM categories c
                LEFT JOIN supercategories sg ON sg.id = c.super_category_id
                JOIN products p ON p.category_id = c.id
                WHERE p.stock > 0
                GROUP BY c.id, c.name, sg.id, sg.name
                UNION ALL
                SELECT "subcategory" AS type, sc.id, sc.name, c.name AS parent, sg.id AS super_id
                FROM subcategories sc
                JOIN products p ON p.subcategory_id = sc.id
                LEFT JOIN categories c ON c.id = sc.category_id
                LEFT JOIN supercategories sg ON sg.id = c.super_category_id
                WHERE p.stock > 0
                GROUP BY sc.id, sc.name, c.name, sg.id';

        foreach ($this->pdo->query($sql)->fetchAll() as $row) {
            if ($this->isGenericBucketName($row['name'])) {
                continue;
            }

            $entry = [
                'type'   => $row['type'],
                'id'     => (int) $row['id'],
                'name'   => $row['name'],
                'parent' => $row['parent'],
                'super_id' => isset($row['super_id']) ? (int) $row['super_id'] : null,
            ];

            $this->addBucketAlias($map, $this->bucketKey($row['name']), $entry);

            foreach ($this->bucketAliases($row['name']) as $alias) {
                $this->addBucketAlias($map, $this->bucketKey($alias), $entry);
            }
        }

        foreach ($this->hardBucketAliases() as $alias => $needle) {
            $entry = $this->findBucketEntry($map, $needle['type'], $needle['name'], $needle['parent']);
            if ($entry !== null) {
                $this->addBucketAlias($map, $this->bucketKey($alias), $entry);
            }
        }

        $this->bucketCache = $map;
        return $this->bucketCache;
    }

    /**
     * @param array  $map
     * @param string $key
     * @param array  $entry
     * @return void
     */
    private function addBucketAlias(array &$map, $key, array $entry)
    {
        if ($key === '') {
            return;
        }

        if (!isset($map[$key])) {
            $map[$key] = [];
        }

        foreach ($map[$key] as $existing) {
            if ($existing['type'] === $entry['type'] && (int) $existing['id'] === (int) $entry['id']) {
                return;
            }
        }

        $map[$key][] = $entry;
    }

    /**
     * @param array       $map
     * @param string      $type
     * @param string      $name
     * @param string|null $parent
     * @return array|null
     */
    private function findBucketEntry(array $map, $type, $name, $parent = null, $scopeSupercategoryId = null)
    {
        $wantedName   = Text::normalize($name);
        $wantedParent = $parent !== null ? Text::normalize($parent) : null;

        foreach ($map as $entries) {
            foreach ($entries as $entry) {
                if ($entry['type'] !== $type || Text::normalize($entry['name']) !== $wantedName) {
                    continue;
                }
                if ($wantedParent !== null && Text::normalize((string) $entry['parent']) !== $wantedParent) {
                    continue;
                }
                if (!$this->bucketEntryMatchesScope($entry, $scopeSupercategoryId)) {
                    continue;
                }
                return $entry;
            }
        }

        return $this->findBucketEntryDirect($type, $name, $parent, $scopeSupercategoryId);
    }

    /**
     * @param array    $entry
     * @param int|null $scopeSupercategoryId
     * @return bool
     */
    private function bucketEntryMatchesScope(array $entry, $scopeSupercategoryId = null)
    {
        if ($scopeSupercategoryId === null) {
            return true;
        }

        if ($entry['type'] === 'supercategory') {
            return (int) $entry['id'] === (int) $scopeSupercategoryId;
        }

        return isset($entry['super_id']) && (int) $entry['super_id'] === (int) $scopeSupercategoryId;
    }

    /**
     * Fallback for deliberately skipped generic bucket names when a hard alias
     * or intent rule supplies the parent category. Without this, "audio
     * adapter" cannot target Audio > Adapteri i konektori because the generic
     * "Adapteri i konektori" bucket is not put into the normal lookup map.
     *
     * @param string      $type
     * @param string      $name
     * @param string|null $parent
     * @return array|null
     */
    private function findBucketEntryDirect($type, $name, $parent = null, $scopeSupercategoryId = null)
    {
        $wantedName   = Text::normalize($name);
        $wantedParent = $parent !== null ? Text::normalize($parent) : null;

        if ($type === 'supercategory') {
            $rows = $this->pdo->query(
                'SELECT DISTINCT "supercategory" AS type, sg.id, sg.name, NULL AS parent, sg.id AS super_id
                 FROM supercategories sg
                 JOIN categories c ON c.super_category_id = sg.id
                 JOIN products p ON p.category_id = c.id
                 WHERE p.stock > 0'
            )->fetchAll();
        } elseif ($type === 'category') {
            $rows = $this->pdo->query(
                'SELECT DISTINCT "category" AS type, c.id, c.name, sg.name AS parent, sg.id AS super_id
                 FROM categories c
                 LEFT JOIN supercategories sg ON sg.id = c.super_category_id
                 JOIN products p ON p.category_id = c.id
                 WHERE p.stock > 0'
            )->fetchAll();
        } else {
            $rows = $this->pdo->query(
                'SELECT DISTINCT "subcategory" AS type, sc.id, sc.name, c.name AS parent, sg.id AS super_id
                 FROM subcategories sc
                 JOIN products p ON p.subcategory_id = sc.id
                 LEFT JOIN categories c ON c.id = sc.category_id
                 LEFT JOIN supercategories sg ON sg.id = c.super_category_id
                 WHERE p.stock > 0'
            )->fetchAll();
        }

        foreach ($rows as $entry) {
            if (Text::normalize($entry['name']) !== $wantedName) {
                continue;
            }
            if ($wantedParent !== null && Text::normalize((string) $entry['parent']) !== $wantedParent) {
                continue;
            }
            if (!$this->bucketEntryMatchesScope($entry, $scopeSupercategoryId)) {
                continue;
            }

            return [
                'type'   => $entry['type'],
                'id'     => (int) $entry['id'],
                'name'   => $entry['name'],
                'parent' => $entry['parent'],
                'super_id' => isset($entry['super_id']) ? (int) $entry['super_id'] : null,
            ];
        }

        return null;
    }

    /**
     * @param string $name
     * @return string[]
     */
    private function bucketAliases($name)
    {
        $norm = Text::normalize($name);

        $aliases = [
            // 'zaslon' is the Croatian term for screen/monitor - found
            // 2026-08-26 returning zero results.
            'monitori' => ['monitor', 'monitore', 'zaslon', 'zasloni', 'zaslone'],
            'misevi' => ['mis', 'miseve', 'miševe'],
            'laptopi' => ['laptop', 'laptope', 'macbook', 'mackbook', 'mek buk', 'mekbuk'],
            'televizori' => ['tv', 'televizor', 'televizore'],
            // Found 2026-08-26: no separate "fitness band" category exists
            // here - fitness trackers are sold as Smartwatch models, so
            // "fitnes narukvica" was matching an unrelated LEGO toy bracelet
            // instead (bare "narukvica" appears nowhere in a real
            // smartwatch's name).
            'smartwatch' => ['pametni sat', 'smart watch', 'smart sat', 'rucni sat', 'ručni sat', 'fitnes narukvica', 'fitness narukvica', 'narukvica za fitnes', 'fitnes trekker', 'fitness tracker'],
            'router' => ['routere', 'rutere', 'ruter', 'rutera'],
            // Found 2026-08-26: "pojačivač signala wifi" matched an
            // unrelated AC unit's WiFi module instead - real products here
            // are named "Wireless ... Extender-Access Point...", never
            // "pojačivač".
            // "access point" (bare, the literal networking term) is itself
            // missing here too - found 2026-08-27: the bucket's own real
            // name is the compound "Access point / Extenderi", so
            // bucketKey() combining every word means the bare two-word
            // term alone never matches it, same gap as the synonyms below.
            'access point extenderi' => ['access point', 'pojacivac signala', 'pojacivac wifi signala', 'pojacivac signala wifi', 'wifi extender', 'wifi repetitor', 'pojacivac wifi', 'wifi antene', 'wifi antena', 'wi fi antene', 'wi fi antena', 'bezicne antene', 'bezicna antena'],
            // "Smart plug" (English) never appears in a product name here -
            // found 2026-08-26 matching Smartphones instead ("smart" alone
            // is a substring of both). Real products are "Pametna
            // utičnica...".
            'strujni razdelnici' => ['smart plug', 'smart uticnica', 'pametni plug'],
            // "Ugaona" is the Serbian term for "kutna" (angle, as in angle
            // grinder) - found 2026-08-26 returning zero results. This
            // bucket also holds orbital and bench grinders/sanders, so the
            // word must be substituted (see queryForBucketSearch below),
            // not just stripped, or the search could land on the wrong
            // grinder type.
            'brusilice' => ['ugaona brusilica', 'ugaona brusilka'],
            // "Prečistač zraka" (pre-) is a common dialectal spelling next
            // to the catalog's own "pročišćivač" (pro-) - genuinely
            // different vowel, not just missing diacritics. Found
            // 2026-08-26 returning zero results, after wrongly telling the
            // user air purifiers were not carried at all.
            'cistaci zraka' => ['precistac vazduha', 'precistac zraka', 'preciscivac vazduha', 'preciscivac zraka'],
            // "Kućno kino" (home theater) never appears in a product name
            // here - the closest real equivalent this catalog sells is a
            // soundbar. Found 2026-08-26 matching a Kindle e-reader
            // instead, after wrongly telling the user home theater systems
            // were not carried at all.
            'soundbar' => ['kucno kino', 'kucni bioskop', 'zvucni sistem za tv'],
            // "Tuš baterija" IS a real, literal phrase in several product
            // names here, but bare "tuš baterija" resolved to power-tool
            // batteries instead (found 2026-08-26) - "baterija" alone is far
            // too common a word elsewhere in the catalog for it to win the
            // head-word contest against "tuš" on its own. Routing the exact
            // phrase through the bucket alias sidesteps that entirely.
            'slavine i tus baterije' => ['tus baterija', 'tus baterije', 'tus bateriju'],
            // "Vejp"/"vape" (English/phonetic) never appears in a product
            // name here - they are all named "Cigareta elektronska...".
            // "Ajfon" is a common phonetic spelling of "iPhone".
            'maska za iphone' => ['maska za ajfon', 'futrola za ajfon', 'maske za ajfon'],
            // "Novogodišnje lampice"/"lampice za jelku" (Christmas lights)
            // never appear in a product name here - real products are all
            // named "Dekorativna LED rasvjeta".
            'novogodisnji program' => ['novogodisnje lampice', 'lampice za jelku', 'bozicne lampice', 'novogodisnja rasvjeta'],
            // 'pisač' is the Croatian term for printer - found 2026-08-26
            // returning zero results.
            'printeri i skeneri' => ['printer', 'printere', 'skener', 'pisac', 'pisaci', 'pisace'],
            // Found 2026-08-27, sweep of the "Energija" supercategory:
            // "izolaciona traka" is at least as common as this bucket's own
            // "izolir traka" (same thing, different adjective).
            'izolir trake' => ['izolaciona traka', 'izolacione trake', 'izolacijska traka'],
            // "Fluorescentna cijev" is the standard technical term for the
            // same tube lights - real products are all "Sijalica, LED
            // cijev...", never "fluorescentna".
            'neonke' => ['fluorescentna cijev', 'fluorescentne cijevi', 'fluo cijev'],
            // Real products are "Programator vremena..." - "tajmer" (the
            // extremely common colloquial word for exactly this) shares no
            // root with that at all.
            'timeri' => ['tajmer', 'tajmer za struju', 'tajmer utikac', 'vremenski prekidac'],
            // The bucket's own name is literally the English "Zinc-Carbon",
            // and real products keep that spelling - "cink karbon" (the
            // natural Bosnian phonetic spelling) shares no matching prefix
            // with it at all.
            'zinc carbon baterije' => ['cink karbon', 'cink karbon baterija', 'cink karbonska baterija'],
            // "Inverter" (12V DC -> 220V AC) is standard English/technical
            // terminology for exactly what these "Adapter 12V na 220V..."
            // products are - never appears in a real product name here.
            'pretvaraci napona' => ['inverter', 'inverteri', 'invertor'],
            // "cd disk"/"prazan cd" - real products are all named "CD-R
            // 700MB..."/"DVD-R...", so the bare word "cd"/"dvd" alone (2-3
            // chars) never combines with "disk"/"prazan" into this bucket's
            // own 2-word "cd dvd" key. Found 2026-08-27.
            'cd dvd' => ['cd disk', 'cd diskovi', 'prazan cd', 'prazni cd', 'dvd disk', 'prazan dvd'],
            // Found 2026-08-27, sweep of Security/TV & SAT: real products
            // are "Smargo čitač kartica"/"Conax CAM modul" - the bucket's
            // own combined name never matches any single natural phrasing.
            'kartice i ci moduli' => ['smart kartica za dekoder', 'kartica za dekoder', 'ci modul', 'kartica za dekodiranje kanala', 'citac kartica'],
            // Real products are "Elektronska sigurnosna brava..."/"Elektro
            // prihvatnik za vrata" - the bucket's own name "Elektronske
            // brave" shares no root with the equally natural "električna
            // brava".
            'elektronske brave' => ['elektricna brava', 'elektricne brave', 'brava za vrata daljinska', 'daljinska brava'],
            // Real products are "Kontrolna ploča sa LAN komunikatorom...",
            // never "centrala"/"centralna jedinica" - without this, a
            // generic alarm gadget (loosely matching "alarm") won instead of
            // the actual control panel.
            'ja 100 centralne jedinice' => ['alarm centrala', 'centralna jedinica alarm', 'centrala za alarm', 'alarmna centrala'],
            // 'mikrotalasna' is the Serbian (ekavica) term for the same
            // appliance ("mikrovalna" is Bosnian/Croatian) - found
            // 2026-08-26 returning zero results.
            'mikrovalne pecnice' => ['mikrotalasna', 'mikrotalasne', 'mikrotalasnu'],
            // 'rerna' is the Serbian term for a built-in oven ("pećnica" is
            // Bosnian/Croatian) - found 2026-08-26 returning zero results.
            'ugradbena pecnica' => ['rerna', 'rerne', 'rernu', 'rernom'],
            // 'perilica (rublja)' is the Croatian term for the same
            // machine (relevant for zed.hr customers, but also seen from
            // Bosnian speakers) - found 2026-08-26 returning zero results.
            'masina za pranje vesa' => ['ves masine', 'veš mašine', 'masine za ves', 'perilica rublja', 'perilica za rublje', 'perilice rublja', 'perilica'],
            'masina za susenje vesa' => ['susilice', 'sušilice', 'masine za susenje vesa'],
            'masina za pranje vesa susilica' => ['ves masina susilica', 'masina za pranje i susenje vesa'],
            'klima split' => ['klime', 'klima uredjaje', 'klima uređaje'],
            // 'trotinet' is a common regional term for the same scooter -
            // found 2026-08-26 returning zero results.
            'elektricni romobili' => ['romobile', 'elektricne romobile', 'trotinet', 'trotineti', 'trotinetu', 'elektricni trotinet'],
            // The real subcategory is literally spelled "eBike" (English) -
            // found 2026-08-27: the natural Bosnian phrase "bicikl
            // elektricni"/"elektricni bicikl" shares no word with that at
            // all, so it fell through to the "elektricni" word alone, which
            // then matched Električni romobili (scooters) instead - a
            // different, wrong product a customer asking for a bike does
            // not want.
            'ebike' => ['bicikl', 'bicikla', 'biciklu', 'elektricni bicikl', 'bicikl elektricni', 'gradski bicikl', 'elektricni bicikl gradski'],
            // Found 2026-08-27, systematic sweep of remaining compound
            // buckets: "vezica"/"obujmica" (cable tie/clamp) alone never
            // matched this bucket's own combined name.
            'vezice i obujmice' => ['vezica za kablove', 'vezice za kablove', 'obujmica za kabl', 'obujmica za kablove', 'plasticne vezice', 'kablovske vezice'],
            // "Kamin" alone already resolves fine ("Kamin, električni...")
            // but adding the natural qualifier "na struju" changed the
            // combined bucketKey() enough to miss the alias, and "struju"
            // alone collides with Strujni razdjelnici (power strips) -
            // same shape as "šrafciger elektricni" above.
            'elektricni kamini' => ['kamin na struju', 'strujni kamin', 'kamin struja'],
            // "Klopka" is a common colloquial synonym for "zamka" (trap) -
            // real products are all named "Zamka za miševe...".
            'zamke za insekte glodare' => ['klopka za misa', 'klopka za miseve', 'klopka za pacove'],
            // "Navlaka" is a common synonym for "futrola"/"etui" here -
            // without it, bare "navlaka za tablet" matched actual tablets
            // instead (the generic word "tablet" dominating with no cover-
            // specific bucket to anchor on).
            'futrole etui za tablet' => ['navlaka za tablet', 'navlaka za ipad'],
            // "Konektor scart" alias moved into the existing 'scart' entry
            // further below (a duplicate array key here would have silently
            // overwritten - and lost - that entry's own aliases).
            // 'šrafciger' is a common colloquial spelling of "odvijač" -
            // found 2026-08-26 returning zero results. Products are named
            // "Odvijač...", not this bucket's own name "Izvijači".
            // "elektricni sarafciger"/"izvijac elektricni" found 2026-08-27:
            // bare "srafciger"/"izvijac" already worked, but adding the
            // extremely natural qualifier "električni" changed the combined
            // bucketKey() enough to miss every alias above - same shape as
            // the compound-name gaps already fixed elsewhere in this list,
            // just with the customer's own added word instead of the
            // bucket's own name being the multi-word culprit.
            'klijesta i izvijaci' => [
                'srafciger', 'srafcigeri', 'srafcigera',
                // "šarafciger" (with the extra "a") is at least as common a
                // colloquial spelling as "šrafciger" - found 2026-08-27 it
                // did not resolve at all, bare or with "električni".
                'sarafciger', 'sarafcigeri', 'sarafcigera',
                'elektricni srafciger', 'srafciger elektricni',
                'elektricni sarafciger', 'sarafciger elektricni',
                'izvijac elektricni', 'elektricni izvijac',
            ],
            'daljinski upravljaci' => ['daljinske', 'daljinski upravljac'],
            // "nosač za televizor" (the full word) never matches this
            // bucket's own bucketKey() - only the abbreviation "TV" does
            // ("tv nosač" already worked). Found 2026-08-27: "televizor"
            // has no shared stem with "tv" at all, and without a bucket
            // match the plain word "televizor" dominates the head-word
            // race and returns actual TVs instead of mounts for them.
            'tv nosaci' => ['nosac za televizor', 'zidni nosac za televizor', 'nosac televizor'],
            'aparati za brijanje' => ['brijace', 'aparat za brijanje'],
            'fen za kosu' => ['fenove', 'fen'],
            'usisavaci' => ['usisivace', 'usisavače', 'usisivac'],
            // Product names only ever say "Desktop PC" / "Desktop AiO",
            // never "računar" - the word most customers actually type.
            // 'računalo' is the Croatian term for the same thing - found
            // 2026-08-26 returning zero results.
            'desktop aio racunari' => ['racunar', 'racunari', 'racunare', 'racunara', 'kompjuter', 'kompjuteri', 'kompjutere', 'kompjutera', 'racunalo', 'racunala', 'racunalu', 'kompic', 'kompici'],
            // "Fleš" is a common phonetic spelling of "flash" - found
            // 2026-08-26 that "fleš memorija" matched RAM sticks instead
            // (they are literally named "Memorija DDR4...", while USB
            // flash drives never say "memorija" in their own product name,
            // only in this subcategory's name).
            'usb memorija' => ['usb stik', 'usb stick', 'usb flash', 'flash drive', 'memorijski stik', 'fles memorija', 'flash memorija', 'usb fles', 'fles disk', 'flash disk'],
            // "Testera" is the Serbian term for a saw. This bucket also
            // holds planers ("blanje"), so bare "testera" needs the
            // catalog's own word substituted in (see the queryForBucketSearch
            // branch below), not just stripped - otherwise it falls through
            // to a category browse that could return a planer instead.
            'blanje pile' => ['testera', 'testere', 'testeru'],
            'uredska oprema' => ['kancelarijski materijal', 'kancelarijski pribor', 'kancelarijska oprema', 'uredski pribor', 'uredski materijal'],
            'bojleri' => ['bojlere', 'bojler'],
            'nape' => ['napa', 'napu', 'kuhinjske nape', 'kuhinjska napa'],
            'sporeti' => ['sporete', 'šporete'],
            'stednjaci' => ['stednjake', 'štednjake'],
            'kablovi' => ['kablove', 'kabeli', 'kabele'],
            'hdmi' => ['hdmi kabl', 'hdmi kabel', 'hdmi kablove'],
            'scart' => ['scart kabl', 'scart kabel', 'konektor scart', 'scart konektor'],
            's vhs' => ['svhs', 's-vhs kabl', 's vhs kabl'],
            'display port' => ['displayport', 'display port kabl', 'displayport kabl', 'dp kabl'],
            'vga dvi' => ['vga kabl', 'vga kabel'],
            'usb produzni kabel' => ['usb produzni kabl', 'usb produžni kabl', 'usb produžni kabel', 'usb hub', 'usb hub type c', 'type c hub'],
            'usb kabel za printer' => ['usb kabl za printer', 'printer usb kabl', 'printer kabl'],
            'patch kablovi' => ['lan kabl', 'lan kabel', 'mrezni kabl', 'mrežni kabel', 'utp kabl', 'ethernet kabl', 'internet kabl'],
            'mrezni kabel na metar' => ['mrezni kabl na metar', 'mrežni kabel na metar', 'kabl na metar'],
            'kabeli koaksijalni' => ['koaksijalni kabl', 'koaksijalni kabel', 'antenski kabl', 'antenski kabel', 'rg6 kabl', 'rg-6 kabl', 'tv sat kabl'],
            'telefonski kabel' => ['telefonski kabl', 'telefon kabl', 'rj11 kabl'],
            'kabel za slusalicu' => ['kabl za slusalicu', 'kabel za slušalicu'],
        ];

        return isset($aliases[$norm]) ? $aliases[$norm] : [];
    }

    /**
     * Aliases that need parent disambiguation because multiple buckets may
     * share the same visible name.
     *
     * @return array<string,array{type:string,name:string,parent:string|null}>
     */
    private function hardBucketAliases()
    {
        return [
            // The bare word "telefon" is the literal name of the LANDLINE
            // phones subcategory ("Telefoni"), so it wins the normal
            // bucket-name lookup by construction - but in 2026 conversational
            // Bosnian, "telefon" (bare, or "samsung telefon", "koji telefon
            // imate") overwhelmingly means a mobile phone, not a landline
            // handset. Found 2026-08-26: "samsung telefon do 1000 km"
            // silently returned cordless landline phones (which Samsung does
            // not even make) while three real Samsung smartphones existed
            // well under that budget - the search itself was quietly
            // answering the wrong category, not "not found". A customer who
            // actually wants a landline still reaches it via a more specific
            // phrase ("fiksni telefon", "bežični telefon za kuću") - those do
            // not hit this exact bare-word key.
            'telefon' => ['type' => 'subcategory', 'name' => 'Smartphone', 'parent' => 'Prijenosni uređaji'],
            'telefoni' => ['type' => 'subcategory', 'name' => 'Smartphone', 'parent' => 'Prijenosni uređaji'],
            'telefona' => ['type' => 'subcategory', 'name' => 'Smartphone', 'parent' => 'Prijenosni uređaji'],
            'telefonu' => ['type' => 'subcategory', 'name' => 'Smartphone', 'parent' => 'Prijenosni uređaji'],
            'gaming miseve' => ['type' => 'subcategory', 'name' => 'Miševi', 'parent' => 'Periferija'],
            'gaming mis' => ['type' => 'subcategory', 'name' => 'Miševi', 'parent' => 'Periferija'],
            'gaming tastatura' => ['type' => 'subcategory', 'name' => 'Tastature', 'parent' => 'Periferija'],
            'gaming tastature' => ['type' => 'subcategory', 'name' => 'Tastature', 'parent' => 'Periferija'],
            'gaming slusalica' => ['type' => 'subcategory', 'name' => 'Slušalice', 'parent' => 'Periferija'],
            'gaming slusalice' => ['type' => 'subcategory', 'name' => 'Slušalice', 'parent' => 'Periferija'],
            // Products are named "Stolica, gaming, ..." - the bucket's own
            // name is "Gaming stolice" (gaming-first), so a bare "stolica"
            // query does not match it via the normal name-prefix lookup.
            // The catalog carries no other kind of chair, so this is safe.
            // "Vejp"/"vape" never appear in a product name here - real
            // products are all named "Cigareta elektronska...". Needs the
            // subcategory-specific hard alias, not a plain bucketAliases()
            // entry: the category "E Cigarete" and the subcategory
            // "E-Cigarete" normalize to the exact same string, so a plain
            // alias got inserted under both and bucketForQuery() then saw
            // two competing matches for the same key and gave up as
            // ambiguous - found 2026-08-26.
            // A bare console name almost always means the console itself,
            // not one of the many games/accessories that also carry
            // "PlayStation 5"/"Xbox" in their own name - found 2026-08-26:
            // "ps5"/"xbox"/"playstation 5"/"nintendo switch" all returned
            // either a game or nothing, while real consoles were in stock.
            // "konzola" alone already works (it is literally the 2nd word
            // of every real console's name, e.g. "Igraća konzola
            // PlayStation 5..."), so it needs no alias here.
            'ps5' => ['type' => 'subcategory', 'name' => 'Igraće konzole', 'parent' => 'Gaming & Zabava'],
            'ps 5' => ['type' => 'subcategory', 'name' => 'Igraće konzole', 'parent' => 'Gaming & Zabava'],
            'playstation 5' => ['type' => 'subcategory', 'name' => 'Igraće konzole', 'parent' => 'Gaming & Zabava'],
            'plejstejsn 5' => ['type' => 'subcategory', 'name' => 'Igraće konzole', 'parent' => 'Gaming & Zabava'],
            'xbox' => ['type' => 'subcategory', 'name' => 'Igraće konzole', 'parent' => 'Gaming & Zabava'],
            'x box' => ['type' => 'subcategory', 'name' => 'Igraće konzole', 'parent' => 'Gaming & Zabava'],
            'nintendo switch' => ['type' => 'subcategory', 'name' => 'Igraće konzole', 'parent' => 'Gaming & Zabava'],
            // "Sajla" IS the literal word in the real product name ("Zaštitni
            // kabl / sajla za laptop sa ključem") - this is not a synonym
            // gap. The bug is that headToken() picks "laptop" over "sajla"
            // as the anchor once both words are present (laptop is a far
            // more common word across the whole catalog), so the search
            // returned actual laptops instead of the lock cable. Route the
            // whole phrase to the bucket directly instead, sidestepping
            // head selection entirely. Found 2026-08-26.
            // The real bucket name is a 3-way compound ("Bušilice, izvijači
            // i pribor" = drills, [drill/driver] screwdrivers and
            // accessories), so its own bucketKey is the combined
            // "busilic izvijac pribor" - a customer saying just "bušilica"
            // (drill) alone, the overwhelmingly normal way to ask, never
            // matches that combined key on its own. Found 2026-08-26 when
            // "koje marke bušilica imate" fell through to a plain product
            // search instead of the brand chips, because the bucket never
            // resolved at all.
            'busilica' => ['type' => 'subcategory', 'name' => 'Bušilice, izvijači i pribor', 'parent' => 'Električni alati i pribor'],
            'busilice' => ['type' => 'subcategory', 'name' => 'Bušilice, izvijači i pribor', 'parent' => 'Električni alati i pribor'],
            'busilicu' => ['type' => 'subcategory', 'name' => 'Bušilice, izvijači i pribor', 'parent' => 'Električni alati i pribor'],
            'busilicom' => ['type' => 'subcategory', 'name' => 'Bušilice, izvijači i pribor', 'parent' => 'Električni alati i pribor'],
            // Same "compound bucket name, customer says just the first
            // noun" gap as bušilica above - found 2026-08-26 by
            // deliberately checking every other compound-named bucket in
            // the catalog for the identical shape of bug. Each of these
            // was verified individually to not already resolve to
            // anything (bucketForQuery() returned null), so adding it here
            // cannot override or collide with an existing exact-name match
            // the way a blanket "always register the first word" attempt
            // did (tried and reverted the same day - it silently broke
            // "PC", "Adapteri", "Alarm", "Audio", "Ethernet", "Telefoni",
            // "tv", "skener", "Baterije", "Grijalice" and "Posuđe", each of
            // which already owned its OWN word cleanly before a compound
            // bucket sharing that same first word started colliding with
            // it - caught by tools/audit_search_quality.php).
            'projektor' => ['type' => 'subcategory', 'name' => 'Projektori i platna', 'parent' => 'Televizori i oprema'],
            'projektori' => ['type' => 'subcategory', 'name' => 'Projektori i platna', 'parent' => 'Televizori i oprema'],
            'stolna lampa' => ['type' => 'subcategory', 'name' => 'Stolne lampe i noćna svjetla', 'parent' => 'Rasvjeta'],
            'stolne lampe' => ['type' => 'subcategory', 'name' => 'Stolne lampe i noćna svjetla', 'parent' => 'Rasvjeta'],
            'noz' => ['type' => 'subcategory', 'name' => 'Noževi i oštrači za noževe', 'parent' => 'Posuđe i kuhinjski pribor'],
            'nozevi' => ['type' => 'subcategory', 'name' => 'Noževi i oštrači za noževe', 'parent' => 'Posuđe i kuhinjski pribor'],
            'depilator' => ['type' => 'subcategory', 'name' => 'Depilatori i epilatori', 'parent' => 'Osobna njega / Zdravlje'],
            'epilator' => ['type' => 'subcategory', 'name' => 'Depilatori i epilatori', 'parent' => 'Osobna njega / Zdravlje'],
            'rezalica' => ['type' => 'subcategory', 'name' => 'Rezalice i sjeckalice', 'parent' => 'Mali kućanski aparati'],
            'sjeckalica' => ['type' => 'subcategory', 'name' => 'Rezalice i sjeckalice', 'parent' => 'Mali kućanski aparati'],
            'kljesta' => ['type' => 'subcategory', 'name' => 'Kliješta i Izvijači', 'parent' => 'Ručni alati i pribor'],
            'klijesta' => ['type' => 'subcategory', 'name' => 'Kliješta i Izvijači', 'parent' => 'Ručni alati i pribor'],
            'sokovnik' => ['type' => 'subcategory', 'name' => 'Sokovnici i Citrusete', 'parent' => 'Mali kućanski aparati'],
            'sokovnici' => ['type' => 'subcategory', 'name' => 'Sokovnici i Citrusete', 'parent' => 'Mali kućanski aparati'],
            'lemilica' => ['type' => 'subcategory', 'name' => 'Lemilice i pribor', 'parent' => 'Električni alati i pribor'],
            'lemilice' => ['type' => 'subcategory', 'name' => 'Lemilice i pribor', 'parent' => 'Električni alati i pribor'],
            'skalpel' => ['type' => 'subcategory', 'name' => 'Skalpel i Džepni noževi', 'parent' => 'Ručni alati i pribor'],
            'stalak' => ['type' => 'subcategory', 'name' => 'Police, stalci i pribor', 'parent' => 'Repromaterijal'],
            'stalci' => ['type' => 'subcategory', 'name' => 'Police, stalci i pribor', 'parent' => 'Repromaterijal'],
            // "Police" (shelves) is a real, distinct product in this same
            // bucket, separate from "stalak" (stand) above - without this,
            // bare "police" fell through to plain token search and matched
            // "Policijski automobil" (a LEGO police car toy) instead, via
            // the coincidental "polic-" prefix shared with "policijski".
            // Found 2026-08-26 while systematically re-checking every
            // compound bucket a second time.
            'police' => ['type' => 'subcategory', 'name' => 'Police, stalci i pribor', 'parent' => 'Repromaterijal'],
            'polica' => ['type' => 'subcategory', 'name' => 'Police, stalci i pribor', 'parent' => 'Repromaterijal'],
            'sajla za laptop' => ['type' => 'subcategory', 'name' => 'Laptop oprema', 'parent' => 'PC'],
            'zastitna sajla za laptop' => ['type' => 'subcategory', 'name' => 'Laptop oprema', 'parent' => 'PC'],
            // A bare "kabl/kabel za laptop" (no "sajla"/"zastitni" qualifier)
            // means the power cord, not the security lock cable above -
            // "Kabl napojni za laptop" lives in Kabeli napojni/Strujni
            // kabeli instead. Found 2026-08-26 on zed.hr: this was
            // unreachable at all - bucketForQuery() found no match (no
            // subcategory name literally combines "kabel"+"laptop") and
            // intentBucketForQuery() misrouted it to the phone/tablet cable
            // bucket instead (fixed separately, see the $powerCableWord
            // exclusion above). Three entries, not one: "kabl"/"kabel"/
            // "kablovi" stem to three different roots (kabl/kabel/kablov -
            // Text::stem() does not unify the Bosnian "kabl" and Croatian
            // "kabel" spellings), so bucketKey() sorts each to a distinct
            // key and one alias alone would not cover all three spellings.
            'kabl za laptop' => ['type' => 'subcategory', 'name' => 'Kabeli napojni', 'parent' => 'Strujni kabeli'],
            'kabel za laptop' => ['type' => 'subcategory', 'name' => 'Kabeli napojni', 'parent' => 'Strujni kabeli'],
            'kablovi za laptop' => ['type' => 'subcategory', 'name' => 'Kabeli napojni', 'parent' => 'Strujni kabeli'],
            // Two DIFFERENT subcategories are both named "Mjerni
            // instrumenti" - one under "Satelitska oprema" (signal meters),
            // one under "Električni alati i pribor" (the multimeter/clamp
            // meter). A plain bucketAliases() entry (keyed only by the
            // shared name, no parent) would add "multimetar" etc. under
            // BOTH rows and collide - found 2026-08-27, exact same
            // name-collision shape as the "E Cigarete"/"E-Cigarete" case
            // documented earlier this project. hardBucketAliases()'s parent
            // disambiguation is required here, not a plain alias.
            'multimetar' => ['type' => 'subcategory', 'name' => 'Mjerni instrumenti', 'parent' => 'Električni alati i pribor'],
            'ampermetar' => ['type' => 'subcategory', 'name' => 'Mjerni instrumenti', 'parent' => 'Električni alati i pribor'],
            'voltmetar' => ['type' => 'subcategory', 'name' => 'Mjerni instrumenti', 'parent' => 'Električni alati i pribor'],
            // Same head-selection problem as "sajla za laptop" above:
            // headToken() picked "program" over "antivirus", and no real
            // product name contains "program" at all, so nothing matched.
            // Bare "antivirus" alone already works fine via plain token
            // search - this exists only for the "antivirus program"/
            // "antivirus softver" phrasing. Found 2026-08-26.
            'antivirus program' => ['type' => 'subcategory', 'name' => 'Software', 'parent' => 'PC'],
            'antivirus softver' => ['type' => 'subcategory', 'name' => 'Software', 'parent' => 'PC'],
            'vejp' => ['type' => 'subcategory', 'name' => 'E-Cigarete', 'parent' => 'E Cigarete'],
            'vejpovi' => ['type' => 'subcategory', 'name' => 'E-Cigarete', 'parent' => 'E Cigarete'],
            'vejpa' => ['type' => 'subcategory', 'name' => 'E-Cigarete', 'parent' => 'E Cigarete'],
            'vape' => ['type' => 'subcategory', 'name' => 'E-Cigarete', 'parent' => 'E Cigarete'],
            'vaper' => ['type' => 'subcategory', 'name' => 'E-Cigarete', 'parent' => 'E Cigarete'],
            'stolica' => ['type' => 'subcategory', 'name' => 'Gaming stolice', 'parent' => 'Gaming & Zabava'],
            'stolice' => ['type' => 'subcategory', 'name' => 'Gaming stolice', 'parent' => 'Gaming & Zabava'],
            'stolicu' => ['type' => 'subcategory', 'name' => 'Gaming stolice', 'parent' => 'Gaming & Zabava'],
            'stolicom' => ['type' => 'subcategory', 'name' => 'Gaming stolice', 'parent' => 'Gaming & Zabava'],
            'gejmerska stolica' => ['type' => 'subcategory', 'name' => 'Gaming stolice', 'parent' => 'Gaming & Zabava'],
            'gejmerske stolice' => ['type' => 'subcategory', 'name' => 'Gaming stolice', 'parent' => 'Gaming & Zabava'],
            'gaming stolica' => ['type' => 'subcategory', 'name' => 'Gaming stolice', 'parent' => 'Gaming & Zabava'],
            'monitore' => ['type' => 'subcategory', 'name' => 'Monitori', 'parent' => 'PC'],
            'sd kartice' => ['type' => 'subcategory', 'name' => 'Memorijske kartice', 'parent' => 'Memorije i pohrana'],
            'micro sd kartice' => ['type' => 'subcategory', 'name' => 'Memorijske kartice', 'parent' => 'Memorije i pohrana'],
            'memorijske kartice' => ['type' => 'subcategory', 'name' => 'Memorijske kartice', 'parent' => 'Memorije i pohrana'],
            'daljinski' => ['type' => 'subcategory', 'name' => 'Daljinski upravljači', 'parent' => 'Televizori i oprema'],
            'daljinski upravljaci' => ['type' => 'subcategory', 'name' => 'Daljinski upravljači', 'parent' => 'Televizori i oprema'],
            'daljinski upravljac' => ['type' => 'subcategory', 'name' => 'Daljinski upravljači', 'parent' => 'Televizori i oprema'],
            'daljinske' => ['type' => 'subcategory', 'name' => 'Daljinski upravljači', 'parent' => 'Televizori i oprema'],
            'tv daljinski' => ['type' => 'subcategory', 'name' => 'Daljinski upravljači', 'parent' => 'Televizori i oprema'],
            'daljinski za tv' => ['type' => 'subcategory', 'name' => 'Daljinski upravljači', 'parent' => 'Televizori i oprema'],
            'satove' => ['type' => 'subcategory', 'name' => 'Smartwatch', 'parent' => 'Prijenosni uređaji'],
            'satovi' => ['type' => 'subcategory', 'name' => 'Smartwatch', 'parent' => 'Prijenosni uređaji'],
            'pametni satovi' => ['type' => 'subcategory', 'name' => 'Smartwatch', 'parent' => 'Prijenosni uređaji'],
            'pametne satove' => ['type' => 'subcategory', 'name' => 'Smartwatch', 'parent' => 'Prijenosni uređaji'],
            'slusalice' => ['type' => 'subcategory', 'name' => 'Slušalice', 'parent' => 'Zvučnici i slušalice'],
            'slušalice' => ['type' => 'subcategory', 'name' => 'Slušalice', 'parent' => 'Zvučnici i slušalice'],
            'obicne slusalice' => ['type' => 'subcategory', 'name' => 'Slušalice', 'parent' => 'Zvučnici i slušalice'],
            'obične slušalice' => ['type' => 'subcategory', 'name' => 'Slušalice', 'parent' => 'Zvučnici i slušalice'],
            'bluetooth slusalice' => ['type' => 'subcategory', 'name' => 'Slušalice bluetooth', 'parent' => 'Zvučnici i slušalice'],
            'bluetooth slušalice' => ['type' => 'subcategory', 'name' => 'Slušalice bluetooth', 'parent' => 'Zvučnici i slušalice'],
            'bezicne slusalice' => ['type' => 'subcategory', 'name' => 'Slušalice bluetooth', 'parent' => 'Zvučnici i slušalice'],
            'bežične slušalice' => ['type' => 'subcategory', 'name' => 'Slušalice bluetooth', 'parent' => 'Zvučnici i slušalice'],
            'audio kablovi' => ['type' => 'subcategory', 'name' => '3.5 mm / RCA', 'parent' => 'Audio'],
            'audio kabl' => ['type' => 'subcategory', 'name' => '3.5 mm / RCA', 'parent' => 'Audio'],
            'audio kabel' => ['type' => 'subcategory', 'name' => '3.5 mm / RCA', 'parent' => 'Audio'],
            'aux kabl' => ['type' => 'subcategory', 'name' => '3.5 mm / RCA', 'parent' => 'Audio'],
            'aux kabel' => ['type' => 'subcategory', 'name' => '3.5 mm / RCA', 'parent' => 'Audio'],
            'rca kabl' => ['type' => 'subcategory', 'name' => '3.5 mm / RCA', 'parent' => 'Audio'],
            'rca kabel' => ['type' => 'subcategory', 'name' => '3.5 mm / RCA', 'parent' => 'Audio'],
            'cinch kabl' => ['type' => 'subcategory', 'name' => '3.5 mm / RCA', 'parent' => 'Audio'],
            'cinc kabl' => ['type' => 'subcategory', 'name' => '3.5 mm / RCA', 'parent' => 'Audio'],
            'jack kabl' => ['type' => 'subcategory', 'name' => '3.5 mm / RCA', 'parent' => 'Audio'],
            '3.5 mm' => ['type' => 'subcategory', 'name' => '3.5 mm / RCA', 'parent' => 'Audio'],
            '3.5mm' => ['type' => 'subcategory', 'name' => '3.5 mm / RCA', 'parent' => 'Audio'],
            'optički kabl' => ['type' => 'subcategory', 'name' => 'Toslink', 'parent' => 'Audio'],
            'opticki kabl' => ['type' => 'subcategory', 'name' => 'Toslink', 'parent' => 'Audio'],
            'opticki audio kabl' => ['type' => 'subcategory', 'name' => 'Toslink', 'parent' => 'Audio'],
            'kabl za zvucnike' => ['type' => 'subcategory', 'name' => 'Kabeli za zvučnike', 'parent' => 'Audio'],
            'kabel za zvučnike' => ['type' => 'subcategory', 'name' => 'Kabeli za zvučnike', 'parent' => 'Audio'],
            'kablovi za zvucnike' => ['type' => 'subcategory', 'name' => 'Kabeli za zvučnike', 'parent' => 'Audio'],
            'zvucnicki kabl' => ['type' => 'subcategory', 'name' => 'Kabeli za zvučnike', 'parent' => 'Audio'],
            'audio adapter' => ['type' => 'subcategory', 'name' => 'Adapteri i konektori', 'parent' => 'Audio'],
            'audio adapteri' => ['type' => 'subcategory', 'name' => 'Adapteri i konektori', 'parent' => 'Audio'],
            'adapter audio' => ['type' => 'subcategory', 'name' => 'Adapteri i konektori', 'parent' => 'Audio'],
            'adapteri audio' => ['type' => 'subcategory', 'name' => 'Adapteri i konektori', 'parent' => 'Audio'],
            'adapter za slusalice' => ['type' => 'subcategory', 'name' => 'Adapteri i konektori', 'parent' => 'Audio'],
            'profesionalni audio' => ['type' => 'category', 'name' => 'Audio professional', 'parent' => null],
            'audio profesionalni' => ['type' => 'category', 'name' => 'Audio professional', 'parent' => null],
            'professional audio' => ['type' => 'category', 'name' => 'Audio professional', 'parent' => null],
            'profesionalni zvucnici' => ['type' => 'subcategory', 'name' => 'Zvučnici', 'parent' => 'Audio professional'],
            'audio profesionalni zvucnici' => ['type' => 'subcategory', 'name' => 'Zvučnici', 'parent' => 'Audio professional'],
            'profesionalni mikrofon' => ['type' => 'subcategory', 'name' => 'Mikrofoni', 'parent' => 'Audio professional'],
            'profesionalni mikrofoni' => ['type' => 'subcategory', 'name' => 'Mikrofoni', 'parent' => 'Audio professional'],
            'audio mikrofon' => ['type' => 'subcategory', 'name' => 'Mikrofoni', 'parent' => 'Audio professional'],
            'audio pojacalo' => ['type' => 'subcategory', 'name' => '110V pojačalo i zvučnici', 'parent' => 'Audio professional'],
            'pojacalo audio' => ['type' => 'subcategory', 'name' => '110V pojačalo i zvučnici', 'parent' => 'Audio professional'],
            'pojacalo 110v' => ['type' => 'subcategory', 'name' => '110V pojačalo i zvučnici', 'parent' => 'Audio professional'],
            '110v pojacalo' => ['type' => 'subcategory', 'name' => '110V pojačalo i zvučnici', 'parent' => 'Audio professional'],
            'mikseta' => ['type' => 'subcategory', 'name' => '110V pojačalo i zvučnici', 'parent' => 'Audio professional'],
            'razglas' => ['type' => 'subcategory', 'name' => '110V pojačalo i zvučnici', 'parent' => 'Audio professional'],
            'ozvucenje' => ['type' => 'subcategory', 'name' => '110V pojačalo i zvučnici', 'parent' => 'Audio professional'],
            'dijelovi za zvucnike' => ['type' => 'subcategory', 'name' => 'Dijelovi za zvučnike', 'parent' => 'Audio professional'],
            'delovi za zvucnike' => ['type' => 'subcategory', 'name' => 'Dijelovi za zvučnike', 'parent' => 'Audio professional'],
            'mikro prekidaci' => ['type' => 'subcategory', 'name' => 'Mikro prekidači', 'parent' => 'Audio professional'],
            'mikroprekidaci' => ['type' => 'subcategory', 'name' => 'Mikro prekidači', 'parent' => 'Audio professional'],
            'audio professional konektori' => ['type' => 'subcategory', 'name' => 'Konektori ', 'parent' => 'Audio professional'],
            'konektori audio professional' => ['type' => 'subcategory', 'name' => 'Konektori ', 'parent' => 'Audio professional'],
            'profesionalni audio konektori' => ['type' => 'subcategory', 'name' => 'Konektori ', 'parent' => 'Audio professional'],
            'dvi kabl' => ['type' => 'subcategory', 'name' => 'HDMI', 'parent' => 'HDMI & Video'],
            'dvi kabel' => ['type' => 'subcategory', 'name' => 'HDMI', 'parent' => 'HDMI & Video'],
            'dvi kablove' => ['type' => 'subcategory', 'name' => 'HDMI', 'parent' => 'HDMI & Video'],
            'dvi adapter' => ['type' => 'subcategory', 'name' => 'VGA/DVI', 'parent' => 'USB & PC'],
            'dvi konektor' => ['type' => 'subcategory', 'name' => 'VGA/DVI', 'parent' => 'USB & PC'],
            // "pegla" alone means a clothes iron, not a hair straightener - even
            // though both subcategories contain the word "pegle" in their name.
            'pegla' => ['type' => 'subcategory', 'name' => 'Pegle / Glačala', 'parent' => 'Njega rublja'],
            'glacalo' => ['type' => 'subcategory', 'name' => 'Pegle / Glačala', 'parent' => 'Njega rublja'],
            'pegla za kosu' => ['type' => 'subcategory', 'name' => 'Četke/pegle/uvijači za kosu', 'parent' => 'Osobna njega / Zdravlje'],
            'glacalo za kosu' => ['type' => 'subcategory', 'name' => 'Četke/pegle/uvijači za kosu', 'parent' => 'Osobna njega / Zdravlje'],
            // Bare "switch" already resolves correctly on its own, but adding
            // "mrezni" breaks the exact-name bucket match and fulltext then
            // ranks a "Mrežni adapter" (shorter name) above the real switches.
            'switch mrezni' => ['type' => 'subcategory', 'name' => 'Switch', 'parent' => 'Ethernet'],
            'mrezni switch' => ['type' => 'subcategory', 'name' => 'Switch', 'parent' => 'Ethernet'],
            'mrezni ruter' => ['type' => 'subcategory', 'name' => 'Router', 'parent' => 'Ethernet'],
            'ruter mrezni' => ['type' => 'subcategory', 'name' => 'Router', 'parent' => 'Ethernet'],
            // Every real product here is named "Gamepad", never "kontroler" -
            // the word customers actually use. Only wire the platform-specific
            // phrasings, not bare "kontroler" (too generic - also used for
            // drone/TV/AC remotes elsewhere in the catalog).
            'kontroler za ps5' => ['type' => 'subcategory', 'name' => 'Kontroleri & Volani', 'parent' => 'Gaming & Zabava'],
            'kontroler ps5' => ['type' => 'subcategory', 'name' => 'Kontroleri & Volani', 'parent' => 'Gaming & Zabava'],
            'kontroler za ps4' => ['type' => 'subcategory', 'name' => 'Kontroleri & Volani', 'parent' => 'Gaming & Zabava'],
            'kontroler ps4' => ['type' => 'subcategory', 'name' => 'Kontroleri & Volani', 'parent' => 'Gaming & Zabava'],
            'kontroler za xbox' => ['type' => 'subcategory', 'name' => 'Kontroleri & Volani', 'parent' => 'Gaming & Zabava'],
            'kontroler za pc' => ['type' => 'subcategory', 'name' => 'Kontroleri & Volani', 'parent' => 'Gaming & Zabava'],
            'gaming kontroler' => ['type' => 'subcategory', 'name' => 'Kontroleri & Volani', 'parent' => 'Gaming & Zabava'],
            // "sat" alone is a radio alarm clock (Audio > Mini Sistemi), but
            // "za dom" makes it clear the customer means a home clock/weather
            // station, which plain fulltext otherwise loses to the radio
            // clock's shorter, more frequent name.
            'satovi za dom' => ['type' => 'subcategory', 'name' => 'Satovi i vremenske stanice', 'parent' => 'Uređaji za dom'],
            'sat za dom' => ['type' => 'subcategory', 'name' => 'Satovi i vremenske stanice', 'parent' => 'Uređaji za dom'],
            'kucni sat' => ['type' => 'subcategory', 'name' => 'Satovi i vremenske stanice', 'parent' => 'Uređaji za dom'],
            'zidni sat' => ['type' => 'subcategory', 'name' => 'Satovi i vremenske stanice', 'parent' => 'Uređaji za dom'],
            'timer za struju' => ['type' => 'subcategory', 'name' => 'Timeri', 'parent' => 'Strujni kabeli'],
            'baterijska lampa' => ['type' => 'subcategory', 'name' => 'Ručne svjetiljke', 'parent' => 'Rasvjeta'],
            'baterijska svjetiljka' => ['type' => 'subcategory', 'name' => 'Ručne svjetiljke', 'parent' => 'Rasvjeta'],
            'zidna lampa' => ['type' => 'subcategory', 'name' => 'Stropne i zidne svjetiljke', 'parent' => 'Rasvjeta'],
            'zidna svjetiljka' => ['type' => 'subcategory', 'name' => 'Stropne i zidne svjetiljke', 'parent' => 'Rasvjeta'],
            // Products say "Šporet na čvrsto gorivo" - the word "štednjak"
            // only exists in this catalog for kitchen cookers.
            'stednjak na drva' => ['type' => 'subcategory', 'name' => 'Šporeti', 'parent' => 'Šporeti na čvrsto gorivo'],
            'stednjak na cvrsto gorivo' => ['type' => 'subcategory', 'name' => 'Šporeti', 'parent' => 'Šporeti na čvrsto gorivo'],
            // "pročišćivač" is the category-level word; products and the
            // subcategory itself say "čistač".
            'procistivac zraka' => ['type' => 'subcategory', 'name' => 'Čistači zraka', 'parent' => 'Pročišćivači zraka'],
            'prociscivac zraka' => ['type' => 'subcategory', 'name' => 'Čistači zraka', 'parent' => 'Pročišćivači zraka'],
            // "kamera bullet" alone (no ip/analogna qualifier) defaults to
            // the IP variant - without this, fulltext ranks a camera mount
            // accessory ("Podnožje za kameru, 21xx Bullet") above any actual
            // camera, since the accessory's shorter name scores higher.
            'kamera bullet' => ['type' => 'subcategory', 'name' => 'Kamere IP - BULLET', 'parent' => 'Video nadzor - IP'],
            'bullet kamera' => ['type' => 'subcategory', 'name' => 'Kamere IP - BULLET', 'parent' => 'Video nadzor - IP'],
            'analogna kamera bullet' => ['type' => 'subcategory', 'name' => 'Kamere analogne  - BULLET', 'parent' => 'Video nadzor - Analogija'],
            // Products say "RFID kartica"/"RFID Tag", not "kartica za bravu".
            'kartica za bravu' => ['type' => 'subcategory', 'name' => 'Kartice', 'parent' => 'Interfoni i električne brave'],
            'kartica za interfon' => ['type' => 'subcategory', 'name' => 'Kartice', 'parent' => 'Interfoni i električne brave'],
            // Bare "alarm" already browses the whole category correctly
            // (a mix across the Azor/Zodiac/Amiko/Mi Smart Home component
            // lines - there is no single boxed "alarm system" product to
            // sell). These common qualifiers must resolve the same way
            // instead of literal-matching a clock alarm or car alarm.
            'alarm za kucu' => ['type' => 'category', 'name' => 'Alarm', 'parent' => 'Security'],
            'kucni alarm' => ['type' => 'category', 'name' => 'Alarm', 'parent' => 'Security'],
            'alarmni sistem' => ['type' => 'category', 'name' => 'Alarm', 'parent' => 'Security'],
            'sistem alarma' => ['type' => 'category', 'name' => 'Alarm', 'parent' => 'Security'],
            'bezicni alarm' => ['type' => 'category', 'name' => 'Alarm', 'parent' => 'Security'],
            // "video nadzor" (bare) is genuinely split between two real
            // categories here - "Video nadzor - IP" and "Video nadzor -
            // Analogija" - so its own bucketKey() combines both, and
            // neither owns the bare 2-word phrase. Found 2026-08-27: it
            // fell through to an unrelated door-intercom product instead.
            // Default to IP (84 in stock vs 23 for analog, and the modern/
            // WiFi kind is what "video nadzor" means for the overwhelming
            // majority of customers today) rather than asking - a customer
            // who specifically wants analog already says "analogna kamera".
            'kamera' => ['type' => 'category', 'name' => 'Video nadzor - IP', 'parent' => 'Security'],
            'kamere' => ['type' => 'category', 'name' => 'Video nadzor - IP', 'parent' => 'Security'],
            'sigurnosna kamera' => ['type' => 'category', 'name' => 'Video nadzor - IP', 'parent' => 'Security'],
            'sigurnosne kamere' => ['type' => 'category', 'name' => 'Video nadzor - IP', 'parent' => 'Security'],
            'video nadzor' => ['type' => 'category', 'name' => 'Video nadzor - IP', 'parent' => 'Security'],
            'sistem video nadzora' => ['type' => 'category', 'name' => 'Video nadzor - IP', 'parent' => 'Security'],
            'video nadzor sistem' => ['type' => 'category', 'name' => 'Video nadzor - IP', 'parent' => 'Security'],
            // Jablotron is its own dedicated alarm-system line, separate
            // from the generic "Alarm" category.
            'jablotron alarm' => ['type' => 'category', 'name' => 'Jablotron JA-100', 'parent' => null],
            'alarm jablotron' => ['type' => 'category', 'name' => 'Jablotron JA-100', 'parent' => null],
        ];
    }

    /**
     * @param string $text
     * @return string
     */
    private function bucketKey($text)
    {
        // Bucket names sometimes depend on one-character numbers/letters:
        // "Nintendo Switch" vs "Nintendo Switch 2", "PS4" vs "PS5", "G4".
        // Normal product search drops one-character tokens, but bucket matching
        // must keep them so distinct categories do not collapse together.
        $tokens = Text::meaningfulTokens($text, 1);
        if ($tokens === []) {
            return '';
        }

        $out = [];
        foreach ($tokens as $token) {
            $out[] = Text::stem($token);
        }

        sort($out);
        return implode(' ', array_values(array_unique($out)));
    }

    /**
     * @param string $name
     * @return bool
     */
    private function isGenericBucketName($name)
    {
        $norm = Text::normalize($name);

        $generic = [
            'ostalo', 'pribor', 'dodatna oprema', 'oprema', 'rezervni dijelovi',
            'adapteri i konektori', 'kabeli i konektori', 'konektori',
            'alati', 'pribor i ostalo', 'dekorativni program',
        ];

        return in_array($norm, $generic, true);
    }

    /**
     * For a bare brand browse, stock alone is a poor first signal: accessories
     * often have far more units than the main products the brand is known for.
     * Prefer the dominant concrete subcategory, then keep the normal stock
     * ordering inside that group.
     *
     * @param array[] $results
     * @return array[]
     */
    private function rankBrandOnlyResults(array $results)
    {
        $counts = [];
        foreach ($results as $row) {
            $key = $this->brandOnlyGroupKey($row);
            if (!isset($counts[$key])) {
                $counts[$key] = 0;
            }
            $counts[$key]++;
        }

        foreach ($results as $i => $row) {
            $key = $this->brandOnlyGroupKey($row);
            $results[$i]['_brand_group_count'] = isset($counts[$key]) ? (int) $counts[$key] : 0;
            $results[$i]['_brand_accessory'] = $this->looksLikeAccessoryRow($row) ? 1 : 0;
            $results[$i]['_original_order'] = $i;
        }

        usort($results, function ($a, $b) {
            if ((int) $a['_brand_accessory'] !== (int) $b['_brand_accessory']) {
                return (int) $a['_brand_accessory'] < (int) $b['_brand_accessory'] ? -1 : 1;
            }
            if ((int) $a['_brand_group_count'] !== (int) $b['_brand_group_count']) {
                return (int) $a['_brand_group_count'] > (int) $b['_brand_group_count'] ? -1 : 1;
            }
            if ((float) $a['stock'] != (float) $b['stock']) {
                return (float) $a['stock'] > (float) $b['stock'] ? -1 : 1;
            }

            return (int) $a['_original_order'] < (int) $b['_original_order'] ? -1 : 1;
        });

        foreach ($results as $i => $row) {
            unset($results[$i]['_brand_group_count'], $results[$i]['_brand_accessory'], $results[$i]['_original_order']);
        }

        return $results;
    }

    /**
     * @param array $row
     * @return string
     */
    private function brandOnlyGroupKey(array $row)
    {
        $subcategory = isset($row['subcategory']) ? Text::normalize((string) $row['subcategory']) : '';
        if ($subcategory !== '') {
            return $subcategory;
        }

        return isset($row['category']) ? Text::normalize((string) $row['category']) : '';
    }

    /**
     * @param array $row
     * @return bool
     */
    private function looksLikeAccessoryRow(array $row)
    {
        $text = Text::normalize(
            (isset($row['name']) ? (string) $row['name'] : '') . ' '
            . (isset($row['subcategory']) ? (string) $row['subcategory'] : '') . ' '
            . (isset($row['category']) ? (string) $row['category'] : '')
        );

        return preg_match('/\b(?:pribor|oprema|dodat\w*|adapter\w*|punjac\w*|kabl\w*|kabel\w*|maska|maske|futrol\w*|torb\w*|ruksak\w*|stakl\w*|zastit\w*|cetk\w*|crijev\w*|stopic\w*|filter\w*)\b/u', $text) === 1;
    }

    /**
     * @param array[]    $results
     * @param float|null $targetPrice
     * @param float|null  $targetCableLength
     * @param string|null $productPreference
     * @param string|null $sort
     * @param int|null    $targetSpinRpm
     * @return array[]
     */
    private function rankByIntent(array $results, $targetPrice, $targetCableLength, $productPreference, $sort = null, $targetSpinRpm = null)
    {
        foreach ($results as $i => $row) {
            $results[$i]['_original_order'] = $i;
        }

        usort($results, function ($a, $b) use ($targetPrice, $targetCableLength, $productPreference, $sort, $targetSpinRpm) {
            if ($sort === 'discount_desc') {
                $aHasDiscount = isset($a['discount_percent']) && $a['discount_percent'] !== null;
                $bHasDiscount = isset($b['discount_percent']) && $b['discount_percent'] !== null;

                if ($aHasDiscount !== $bHasDiscount) {
                    return $aHasDiscount ? -1 : 1;
                }
                if ($aHasDiscount && $bHasDiscount && (float) $a['discount_percent'] != (float) $b['discount_percent']) {
                    return (float) $a['discount_percent'] > (float) $b['discount_percent'] ? -1 : 1;
                }
            }

            if ($targetSpinRpm !== null) {
                $aRpm = $this->spinSpeedFromName(isset($a['name']) ? $a['name'] : '');
                $bRpm = $this->spinSpeedFromName(isset($b['name']) ? $b['name'] : '');
                $aHasRpm = $aRpm !== null;
                $bHasRpm = $bRpm !== null;

                if ($aHasRpm !== $bHasRpm) {
                    return $aHasRpm ? -1 : 1;
                }
                if ($aHasRpm && $bHasRpm) {
                    $aDistance = abs($aRpm - $targetSpinRpm);
                    $bDistance = abs($bRpm - $targetSpinRpm);

                    // An explicit RPM request should win over a same-category
                    // naming-style preference score ("mašina za pranje veša"
                    // vs the shorter "mašina za veš") - both are equally real
                    // washing machines, so the one closer to the requested
                    // spin speed should rank first either way.
                    if ($aDistance !== $bDistance) {
                        return $aDistance < $bDistance ? -1 : 1;
                    }
                }
            }

            if ($productPreference !== null) {
                $aPreference = $this->preferenceScore($a, $productPreference);
                $bPreference = $this->preferenceScore($b, $productPreference);

                if ($aPreference !== $bPreference) {
                    return $aPreference > $bPreference ? -1 : 1;
                }
            }

            if ($targetCableLength !== null) {
                $aLength = $this->extractCableLengthMeters(isset($a['name']) ? $a['name'] : '');
                $bLength = $this->extractCableLengthMeters(isset($b['name']) ? $b['name'] : '');
                $aHasLength = $aLength !== null;
                $bHasLength = $bLength !== null;

                if ($aHasLength !== $bHasLength) {
                    return $aHasLength ? -1 : 1;
                }
                if ($aHasLength && $bHasLength) {
                    $aDistance = abs($aLength - $targetCableLength);
                    $bDistance = abs($bLength - $targetCableLength);

                    if (abs($aDistance - $bDistance) > 0.01) {
                        return $aDistance < $bDistance ? -1 : 1;
                    }
                }
            }

            if ($sort === 'price_asc' || $sort === 'price_desc') {
                $aHasPrice = isset($a['price']) && $a['price'] !== null;
                $bHasPrice = isset($b['price']) && $b['price'] !== null;

                if ($aHasPrice !== $bHasPrice) {
                    return $aHasPrice ? -1 : 1;
                }
                if ($aHasPrice && $bHasPrice && (float) $a['price'] != (float) $b['price']) {
                    if ($sort === 'price_asc') {
                        return (float) $a['price'] < (float) $b['price'] ? -1 : 1;
                    }
                    return (float) $a['price'] > (float) $b['price'] ? -1 : 1;
                }
            }

            if ($targetPrice === null) {
                return (int) $a['_original_order'] < (int) $b['_original_order'] ? -1 : 1;
            }

            $aHasPrice = isset($a['price']) && $a['price'] !== null;
            $bHasPrice = isset($b['price']) && $b['price'] !== null;

            if ($aHasPrice !== $bHasPrice) {
                return $aHasPrice ? -1 : 1;
            }
            if (!$aHasPrice && !$bHasPrice) {
                return 0;
            }

            $aDistance = abs((float) $a['price'] - $targetPrice);
            $bDistance = abs((float) $b['price'] - $targetPrice);

            if ($aDistance == $bDistance) {
                // With a budget, the higher-priced item usually represents the
                // stronger recommendation when both are equally close.
                return (float) $a['price'] < (float) $b['price'] ? 1 : -1;
            }

            return $aDistance < $bDistance ? -1 : 1;
        });

        foreach ($results as $i => $row) {
            unset($results[$i]['_original_order']);
        }

        return $results;
    }

    /**
     * @param array  $row
     * @param string $preference
     * @return int
     */
    private function preferenceScore(array $row, $preference)
    {
        $category    = isset($row['category']) ? Text::normalize($row['category']) : '';
        $subcategory = isset($row['subcategory']) ? Text::normalize($row['subcategory']) : '';

        if ($preference === 'pc_monitor') {
            $name = isset($row['name']) ? Text::normalize($row['name']) : '';

            if (preg_match('/\b(?:adapter|nosac|drzac|svjetiljk|lampa|kabl|kabel|staklo|cistac|sredstv)\w*\b/u', $name)) {
                return 10;
            }
            if (preg_match('/\b(?:monitor|display|displej)\w*\b/u', $name)) {
                return 100;
            }
            if ($category === 'pc' && $subcategory === 'monitori') {
                return 60;
            }
            if ($subcategory === 'monitori') {
                return 50;
            }
            if ($category === 'pc') {
                return 30;
            }
        }

        if ($preference === 'washing_machine') {
            $name = isset($row['name']) ? Text::normalize($row['name']) : '';

            if (preg_match('/\b(?:nogic|postolj|crijev|crev|filter|sredstv|cistac|navlak|deterdz)\w*\b/u', $name)) {
                return 10;
            }
            if (preg_match('/\b(?:masin\w*\s+za\s+pranj\w*|masin\w*\s+za\s+prenj\w*|ves\s+masin\w*|perilic\w*)\b/u', $name)) {
                return 100;
            }
            if ($subcategory === 'masina za pranje vesa') {
                return 60;
            }
        }

        if ($preference === 'actual_cable') {
            $name = isset($row['name']) ? Text::normalize($row['name']) : '';

            if (preg_match('/\b(?:kabl|kabel)\w*\b/u', $name)) {
                return 100;
            }
            if (preg_match('/\b(?:patch|lan|ethernet|hdmi|scart|toslink|vga|dvi|display\s*port|displayport|rca|cinc|cinch|usb)\b/u', $name)) {
                return 70;
            }
            if (preg_match('/\b(?:adapter|konektor|uticnic|uticac|utikac|razdjelnik|razdelnik|odcjepnik|odcepnik|splitter|konverter|prelaz|nastavak|spojnic)\w*\b/u', $name)) {
                return 10;
            }
        }

        if ($preference === 'actual_connector') {
            $name = isset($row['name']) ? Text::normalize($row['name']) : '';

            if (preg_match('/\b(?:adapter|konektor|uticnic|uticac|utikac|razdjelnik|razdelnik|odcjepnik|odcepnik|splitter|konverter|prelaz|nastavak|spojnic)\w*\b/u', $name)) {
                return 100;
            }
            if (preg_match('/\b(?:kabl|kabel)\w*\b/u', $name)) {
                return 20;
            }
        }

        if ($preference === 'photo_camera') {
            $name = isset($row['name']) ? Text::normalize($row['name']) : '';

            if (preg_match('/\b(?:papir|torbica|torba|stativ|tripod|drzac|nosac|adapter|kabl|baterija|punjac)\b/u', $name)) {
                return 10;
            }
            if (preg_match('/\b(?:kamera|kamere|fotoaparat|fotoaparati|aparat)\b/u', $name)) {
                return 100;
            }
            if ($subcategory === 'fotoaparati kamere') {
                return 60;
            }
        }

        if ($preference === 'gaming_chair') {
            $name = isset($row['name']) ? Text::normalize($row['name']) : '';

            if (preg_match('/\b(?:prostirk|podlog|navlak)\w*\b/u', $name)) {
                return 10;
            }
            if (preg_match('/\bstolic\w*\b/u', $name)) {
                return 100;
            }
        }

        if ($preference === 'pc_case') {
            $name = isset($row['name']) ? Text::normalize($row['name']) : '';

            if (preg_match('/\b(?:ventilator|hladnjak|filter|traka|led)\w*\b/u', $name)) {
                return 10;
            }
            if (preg_match('/\bkucist\w*\b/u', $name)) {
                return 100;
            }
        }

        if ($preference === 'pc_motherboard') {
            $name = isset($row['name']) ? Text::normalize($row['name']) : '';

            if (preg_match('/\b(?:romobil|skuter|baterij\w*|kontrol\w*)\b/u', $name)) {
                return 10;
            }
            if (preg_match('/\bmaticn\w*\s+ploc\w*\s+za\s+pc\b/u', $name)) {
                return 100;
            }
            if ($category === 'pc' && preg_match('/\bmaticn\w*\s+ploc\w*\b/u', $name)) {
                return 90;
            }
            if (preg_match('/\bmaticn\w*\s+ploc\w*\b/u', $name)) {
                return 40;
            }
        }

        if ($preference === 'pc_power_supply') {
            $name = isset($row['name']) ? Text::normalize($row['name']) : '';

            if (preg_match('/\b(?:adapter|romobil|xiaomi|poe|kabl|uticnic|uticnica)\b/u', $name)) {
                return 10;
            }
            if (preg_match('/\b(?:napojn\w*|napajanj\w*)\b/u', $name)
                && preg_match('/\b(?:pc|atx)\b/u', $name)
            ) {
                return 100;
            }
            if ($category === 'pc' && $subcategory === 'ups napajanja') {
                return 70;
            }
        }

        if ($preference === 'gaming_monitor') {
            $name = isset($row['name']) ? Text::normalize($row['name']) : '';

            if (preg_match('/\b(?:vlaznost|vlaznosti|vazduh\w*|temperatur\w*)\b/u', $name)) {
                return 5;
            }
            if (preg_match('/\bmonitor\w*\b/u', $name) && preg_match('/\bgaming\b/u', $name)) {
                return 100;
            }
            if (preg_match('/\bmonitor\w*\b/u', $name)
                && preg_match('/\b(?:120hz|144hz|165hz|170hz|180hz|240hz|odyssey|freesync)\b/u', $name)
            ) {
                return 90;
            }
            if ($category === 'pc' && $subcategory === 'monitori') {
                return 60;
            }
            if (preg_match('/\bmonitor\w*\b/u', $name)) {
                return 50;
            }
        }

        if ($preference === 'pc_desk') {
            $name = isset($row['name']) ? Text::normalize($row['name']) : '';

            if (preg_match('/\b(?:desktop|aio|switch|printer)\b/u', $name)) {
                return 10;
            }
            if (preg_match('/\bradn\w*\s+sto\w*\s+za\s+pc\b/u', $name)) {
                return 100;
            }
            if (preg_match('/\bradn\w*\s+stol?\w*\b/u', $name)) {
                return 80;
            }
        }

        if ($preference === 'mouse_pad') {
            $name = isset($row['name']) ? Text::normalize($row['name']) : '';

            if (preg_match('/\b(?:tastatur\w*|slusalic\w*|mis\b)\b/u', $name)
                && !preg_match('/\bpodlog\w*\b/u', $name)
            ) {
                return 20;
            }
            if (preg_match('/\bpodlog\w*\s+za\s+mis\w*\b/u', $name)) {
                return 100;
            }
            if (preg_match('/\bpodlog\w*\b/u', $name)) {
                return 80;
            }
        }

        if ($preference === 'storage_drive') {
            $name = isset($row['name']) ? Text::normalize($row['name']) : '';

            if (preg_match('/\b(?:kocion\w*|romobil\w*|skuter\w*)\b/u', $name)) {
                return 10;
            }
            if ($subcategory === 'hdd ssd') {
                return 100;
            }
            if (preg_match('/\b(?:ssd|hdd|hard\s+disk|disk)\b/u', $name)
                && $category === 'memorije i pohrana'
            ) {
                return 90;
            }
        }

        if ($preference === 'printer_paper') {
            $name = isset($row['name']) ? Text::normalize($row['name']) : '';

            if ($category === 'priprema hrane' || preg_match('/\b(?:pecenj\w*|fritez\w*)\b/u', $name)) {
                return 10;
            }
            if ($subcategory === 'papir za printanje') {
                return 100;
            }
            if (preg_match('/\bpapir\w*\s+za\s+printer\w*\b/u', $name)) {
                return 90;
            }
        }

        if ($preference === 'shaver') {
            $name = isset($row['name']) ? Text::normalize($row['name']) : '';

            // Replacement parts ("Folija i blok s oštricama za brijaći aparat")
            // still contain the "brijac" stem, so accessories must be checked
            // before the main razor boost below, not after.
            if (preg_match('/\b(?:trimer\s+za\s+nos|folij|blok|ostric)\w*\b/u', $name)) {
                return 10;
            }
            if (preg_match('/\bbrijanj\w*\b/u', $name)) {
                return 100;
            }
        }

        if ($preference === 'stick_vacuum') {
            $name = isset($row['name']) ? Text::normalize($row['name']) : '';

            if (preg_match('/\b(?:stalak|postolj|pribor|drzac|nastavak|vrecic)\w*\b/u', $name)) {
                return 10;
            }
            if (preg_match('/\b(?:usisivac|usisavac)\w*\b/u', $name)) {
                return 100;
            }
        }

        if ($preference === 'pro_audio') {
            if (in_array($subcategory, ['110v pojacalo i zvucnici', 'mikrofoni', 'zvucnici'], true)) {
                return 100;
            }
            if ($subcategory === 'dijelovi za zvucnike') {
                return 80;
            }
            if ($subcategory === 'konektori') {
                return 30;
            }
            if ($subcategory === 'mikro prekidaci') {
                return 0;
            }
        }

        return 0;
    }

    /**
     * New installs get these columns from db/schema.sql. Existing local
     * databases are upgraded lazily here so the chat endpoint does not crash
     * before the next sync run.
     *
     * @return bool
     */
    private function ensureActionColumns()
    {
        $columns = [
            'is_action'    => 'TINYINT(1) NOT NULL DEFAULT 0',
            'action_price' => 'DECIMAL(10,2) NULL',
            'price_before' => 'DECIMAL(10,2) NULL',
            'discount_percent' => 'DECIMAL(5,2) NULL',
            'action_start' => 'VARCHAR(32) NULL',
            'action_end'   => 'VARCHAR(32) NULL',
        ];

        try {
            $existing = [];
            $stmt = $this->pdo->query('SHOW COLUMNS FROM products');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($row['Field'])) {
                    $existing[(string) $row['Field']] = true;
                }
            }

            foreach ($columns as $column => $definition) {
                if (!isset($existing[$column])) {
                    $this->pdo->exec("ALTER TABLE products ADD COLUMN `{$column}` {$definition}");
                }
            }

            try {
                $stmt = $this->pdo->query("SHOW INDEX FROM products WHERE Key_name = 'idx_action'");
                if ($stmt !== false && $stmt->fetch() === false) {
                    $this->pdo->exec('ALTER TABLE products ADD KEY idx_action (is_action)');
                }
            } catch (Throwable $e) {
                // Optional performance index only; the filter still works.
                error_log('ProductSearch: idx_action unavailable — ' . $e->getMessage());
            }

            return true;
        } catch (Throwable $e) {
            error_log('ProductSearch: action columns unavailable — ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Per-storefront visibility columns (is_vp = wholesale/digitalis.ba,
     * is_mp = retail/dstore.ba), added to the API 2026-08-24. Same
     * lazy-migration approach as ensureActionColumns() so an older DB that
     * hasn't been re-synced yet still works without these columns.
     *
     * @return bool
     */
    private function ensureVisibilityColumns()
    {
        $columns = [
            'is_vp' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'is_mp' => 'TINYINT(1) NOT NULL DEFAULT 1',
        ];

        try {
            $existing = [];
            $stmt = $this->pdo->query('SHOW COLUMNS FROM products');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($row['Field'])) {
                    $existing[(string) $row['Field']] = true;
                }
            }

            foreach ($columns as $column => $definition) {
                if (!isset($existing[$column])) {
                    $this->pdo->exec("ALTER TABLE products ADD COLUMN `{$column}` {$definition}");
                }
            }

            return true;
        } catch (Throwable $e) {
            error_log('ProductSearch: visibility columns unavailable — ' . $e->getMessage());
            return false;
        }
    }

    /**
     * The real "Novo" badge flag, added to the API 2026-08-25 at our
     * request (confirmed live on digitalis.ba: 132/10676 products flagged).
     * Same lazy-migration approach as ensureVisibilityColumns().
     *
     * @return bool
     */
    private function ensureNewProductColumn()
    {
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM products LIKE 'new_product'");
            if ($stmt !== false && $stmt->fetch() === false) {
                $this->pdo->exec('ALTER TABLE products ADD COLUMN `new_product` TINYINT(1) NOT NULL DEFAULT 0');
            }

            return true;
        } catch (Throwable $e) {
            error_log('ProductSearch: new_product column unavailable — ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @param bool     $inStockOnly
     * @param int|null $supercategoryId
     * @param int|null $categoryId
     * @param int|null $subcategoryId
     * @param float|null $minPrice
     * @param float|null $maxPrice
     * @param bool     $actionOnly
     * @return array{sql:string,params:array}
     */
    private function buildFilters($inStockOnly, $supercategoryId, $categoryId, $subcategoryId, $minPrice, $maxPrice, $actionOnly = false, $brandId = null, array $excludeIds = [], $visibilityColumn = null, $wholesaleVerified = false, $wholesaleColumn = null, $newOnly = false)
    {
        $sql    = '';
        $params = [];

        if ($inStockOnly) {
            $sql .= ' AND p.stock > 0';
        }
        // Column names, never user input — only 'is_vp'/'is_mp' from config,
        // whitelisted here since a column name can't be bound as a
        // placeholder. Baseline (is_mp on both dstore.ba and the
        // logged-out view of digitalis.ba) always applies; a verified
        // wholesale login widens it to OR in the wholesale-only column
        // (is_vp) instead of narrowing to it, so a logged-in visitor sees
        // strictly more, never less, than an anonymous one.
        if ($this->visibilityColumnsAvailable && in_array($visibilityColumn, ['is_vp', 'is_mp'], true)) {
            if ($wholesaleVerified
                && in_array($wholesaleColumn, ['is_vp', 'is_mp'], true)
                && $wholesaleColumn !== $visibilityColumn
            ) {
                $sql .= " AND (p.{$visibilityColumn} = 1 OR p.{$wholesaleColumn} = 1)";
            } else {
                $sql .= " AND p.{$visibilityColumn} = 1";
            }
        }
        if ($excludeIds !== []) {
            $placeholders = [];
            foreach (array_values($excludeIds) as $i => $excludeId) {
                $key = ':exclude_id' . $i;
                $placeholders[] = $key;
                $params[$key] = (int) $excludeId;
            }
            $sql .= ' AND p.id NOT IN (' . implode(',', $placeholders) . ')';
        }
        if ($brandId !== null && $brandId > 0) {
            $sql .= ' AND p.brand_id = :brand_id';
            $params[':brand_id'] = $brandId;
        }
        if ($supercategoryId !== null && $supercategoryId > 0) {
            $sql .= ' AND c.super_category_id = :supercategory_id';
            $params[':supercategory_id'] = $supercategoryId;
        }
        if ($categoryId !== null && $categoryId > 0) {
            $sql .= ' AND p.category_id = :category_id';
            $params[':category_id'] = $categoryId;
        }
        if ($subcategoryId !== null && $subcategoryId > 0) {
            $sql .= ' AND p.subcategory_id = :subcategory_id';
            $params[':subcategory_id'] = $subcategoryId;
        }
        if ($minPrice !== null && $minPrice > 0) {
            $sql .= ' AND p.price >= :min_price';
            $params[':min_price'] = $minPrice;
        }
        if ($maxPrice !== null && $maxPrice > 0) {
            $sql .= ' AND p.price <= :max_price';
            $params[':max_price'] = $maxPrice;
        }
        if ($actionOnly) {
            $sql .= $this->actionColumnsAvailable ? ' AND p.is_action = 1' : ' AND 1 = 0';
        }
        if ($newOnly) {
            $sql .= $this->newProductColumnAvailable ? ' AND p.new_product = 1' : ' AND 1 = 0';
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * @param string $extraSelect Additional select expression, e.g. a relevance
     *                            score. Goes in the SELECT list, before FROM.
     * @return string
     */
    private function baseSelect($extraSelect = '')
    {
        $extra  = $extraSelect !== '' ? ', ' . $extraSelect : '';
        $action = $this->actionColumnsAvailable
            ? ', p.is_action, p.action_price, p.price_before, p.discount_percent, p.action_start, p.action_end'
            : ', 0 AS is_action, NULL AS action_price, NULL AS price_before, NULL AS discount_percent, NULL AS action_start, NULL AS action_end';
        $newProduct = $this->newProductColumnAvailable
            ? ', p.new_product'
            : ', 0 AS new_product';

        return 'SELECT p.id, p.ean, p.model, p.name, p.description, p.head_word, p.brand_id,
                       p.price, p.stock, p.warranty_months' . $action . $newProduct . ',
                       b.name  AS brand,
                       c.name  AS category,
                       sc.name AS subcategory' . $extra . '
                FROM products p
                LEFT JOIN brands b        ON b.id  = p.brand_id
                LEFT JOIN categories c    ON c.id  = p.category_id
                LEFT JOIN subcategories sc ON sc.id = p.subcategory_id';
    }

    /**
     * @param string $sql
     * @param array  $params
     * @return array[]
     */
    private function run($sql, array $params)
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = $this->shape($row);
        }

        return $out;
    }

    /**
     * Build the live product page link for one deployment of this bot.
     *
     * This same codebase is deployed once per storefront (digitalis.ba,
     * zed.hr, optibox.rs, dstore.ba, ...), each with its own config.local.php
     * pointing at its own catalog - shop_base_url already carries the right
     * domain per deployment. Three of the four known sites share one path
     * shape (digitalis.ba, zed.hr, optibox.rs: /webshop/proizvod/{id}/{seo}).
     * dstore.ba uses /{brand}/{seo}-{id}; shop_url_style picks between them.
     *
     * @param string $shopBase Already rtrim()'d, e.g. "https://www.digitalis.ba".
     * @param int    $id
     * @param string $name
     * @param string $brand
     * @return string
     */
    private function productUrl($shopBase, $id, $name, $brand = '')
    {
        $slug  = self::slugify($name);
        $style = (string) config_get('shop_url_style', 'webshop');

        if ($style === 'flat') {
            $brand = trim((string) $brand);
            $productPath = $slug . '-' . $id;
            if ($brand !== '') {
                return $shopBase . '/' . self::dstoreLabelSegment($brand) . '/' . $productPath;
            }

            return $shopBase . '/' . $productPath;
        }

        return $shopBase . '/webshop/proizvod/' . $id . '/' . $slug;
    }

    /**
     * @param string $text
     * @return string
     */
    private static function dstorePathSegment($text)
    {
        $segment = self::slugify($text);
        return $segment !== '' ? $segment : 'katalog';
    }

    /**
     * @param string $text
     * @return string
     */
    private static function dstoreLabelSegment($text)
    {
        $text = trim((string) $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return rawurlencode($text !== '' ? $text : 'Katalog');
    }

    /**
     * @param string $text
     * @return string
     */
    private static function slugify($text)
    {
        // Text::normalize() keeps anything Unicode calls a "letter" or
        // "number", which includes symbols like Ø (diameter) and ² (from
        // "mm²") that have no business sitting unencoded in a URL path.
        // Strip down to plain ASCII a-z0-9 after it.
        $slug = preg_replace('/[^a-z0-9]+/u', '-', Text::normalize($text));
        $slug = trim((string) $slug, '-');

        return $slug !== '' ? $slug : 'p';
    }

    /**
     * Trim a row down to what a customer-facing answer needs.
     *
     * Descriptions are truncated: the assistant only needs enough to describe
     * the product, and full text for 8 results wastes tokens on every message.
     *
     * @param array $row
     * @return array
     */
    private function shape(array $row)
    {
        $description = (string) $row['description'];
        if (mb_strlen($description) > 300) {
            $description = mb_substr($description, 0, 300) . '…';
        }

        $shopBase = rtrim((string) config_get('shop_base_url', 'https://www.digitalis.ba'), '/');
        $ean      = (string) $row['ean'];

        // Images are not in the product feed, but the Digitalis catalog serves
        // predictable EAN-based images at /slike/Pre/{EAN}_small.png. In the
        // hosted-widget setup this PHP can live on falcom.ba while the catalog
        // images live on the storefront/API domain, so do not default images to
        // this server's public URL.
        $imageBase = rtrim((string) config_get('image_base_url', $this->defaultImageBase()), '/');
        $image = ($ean !== '') ? $imageBase . '/slike/Pre/' . rawurlencode($ean) . '_small.png' : null;

        $url = $this->productUrl(
            $shopBase,
            (int) $row['id'],
            (string) $row['name'],
            $row['brand'] !== null ? (string) $row['brand'] : ''
        );

        return [
            'image'       => $image,
            'url'         => $url,
            // Internal ranking hints, stripped before the result leaves search().
            '_name_starts' => isset($row['name_starts']) ? (int) $row['name_starts'] : 0,
            '_head_word'  => isset($row['head_word']) ? (string) $row['head_word'] : '',
            'id'          => (int) $row['id'],
            'name'        => $row['name'],
            'model'       => $row['model'],
            'brand_id'    => isset($row['brand_id']) ? (int) $row['brand_id'] : 0,
            'brand'       => $row['brand'] !== null ? $row['brand'] : '',
            'category'    => $row['category'] !== null ? $row['category'] : '',
            'subcategory' => $row['subcategory'] !== null ? $row['subcategory'] : '',
            // A price of 0 means "not priced in the feed", not "free".
            // Passing 0 through would have the bot quote 0.00 KM.
            'price'       => ($row['price'] !== null && (float) $row['price'] > 0)
                ? (float) $row['price']
                : null,
            'is_action'   => !empty($row['is_action']),
            'action_price' => ($row['action_price'] !== null && (float) $row['action_price'] > 0)
                ? (float) $row['action_price']
                : null,
            'price_before' => ($row['price_before'] !== null && (float) $row['price_before'] > 0)
                ? (float) $row['price_before']
                : null,
            'discount_percent' => ($row['discount_percent'] !== null && (float) $row['discount_percent'] > 0)
                ? (float) $row['discount_percent']
                : null,
            'action_start' => isset($row['action_start']) && $row['action_start'] !== null ? (string) $row['action_start'] : null,
            'action_end'   => isset($row['action_end']) && $row['action_end'] !== null ? (string) $row['action_end'] : null,
            'is_new'      => !empty($row['new_product']),
            'stock'       => (float) $row['stock'],
            'in_stock'    => ((float) $row['stock']) > 0,
            'warranty_months' => $row['warranty_months'] !== null ? (int) $row['warranty_months'] : null,
            'ean'         => $row['ean'],
            'description' => $description,
        ];
    }

    /**
     * @return string
     */
    private function defaultImageBase()
    {
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

        return rtrim((string) config_get('shop_base_url', 'https://www.digitalis.ba'), '/');
    }
}
