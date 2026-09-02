<?php
/**
 * Automated catalog search-quality audit.
 *
 * This does NOT call OpenAI and does NOT call the Digitalis API. It only
 * tests the local ProductSearch against the synced MySQL catalog, so it is safe
 * and cheap to run often while tuning search.
 *
 * Examples:
 *   php tools/audit_search_quality.php
 *   php tools/audit_search_quality.php --top=50
 *   php tools/audit_search_quality.php --type=subcategory --min-stock=10
 *   php tools/audit_search_quality.php --super="TV & SAT"
 *
 * Target: PHP 7.4.
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Text.php';
require_once __DIR__ . '/../lib/ProductSearch.php';

$opts = parseOptions($argv);

$pdo    = db();
$search = new ProductSearch($pdo);

$targets = [];
if ($opts['type'] === 'all' || $opts['type'] === 'category') {
    $targets = array_merge($targets, categoryTargets($pdo, $opts['min_stock'], $opts['super']));
}
if ($opts['type'] === 'all' || $opts['type'] === 'subcategory') {
    $targets = array_merge($targets, subcategoryTargets($pdo, $opts['min_stock'], $opts['super']));
}

$checked  = 0;
$failures = [];
$passes   = 0;

foreach ($targets as $target) {
    if (!$opts['include_generic'] && isGenericName($target['name'])) {
        continue;
    }

    foreach (queryVariants($target) as $query) {
        $checked++;
        $result = checkTarget($search, $target, $query);

        if ($result['ok']) {
            $passes++;
            continue;
        }

        $failures[] = $result;
    }
}

usort($failures, function ($a, $b) {
    if ($a['severity'] === $b['severity']) {
        return strcmp($a['query'], $b['query']);
    }
    return $b['severity'] - $a['severity'];
});

$shown = array_slice($failures, 0, $opts['top']);

echo "Search quality audit\n";
if ($opts['super'] !== '') {
    echo "Super:    {$opts['super']}\n";
}
echo "Checked:  {$checked}\n";
echo "Passed:   {$passes}\n";
echo "Failed:   " . count($failures) . "\n";
echo "Showing:  " . count($shown) . "\n\n";

foreach ($shown as $i => $failure) {
    echo str_pad((string) ($i + 1), 3, ' ', STR_PAD_LEFT) . '. ';
    echo '[' . $failure['label'] . '] ' . $failure['query'] . "\n";
    echo '     Expected: ' . $failure['expected'] . "\n";
    echo '     Top:      ' . $failure['top'] . "\n";
    echo '     Top 5:    ' . $failure['top5'] . "\n\n";
}

if ($failures !== []) {
    echo "Tip: fix the highest items first. Generic groups like Ostalo/Pribor are skipped by default.\n";
}

// -------------------------------------------------------------------------

/**
 * @param array $argv
 * @return array{top:int,min_stock:int,type:string,include_generic:bool,super:string}
 */
function parseOptions(array $argv)
{
    $opts = [
        'top'             => 30,
        'min_stock'       => 3,
        'type'            => 'all',
        'include_generic' => false,
        'super'           => '',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--top=(\d+)$/', $arg, $m)) {
            $opts['top'] = max(1, min(500, (int) $m[1]));
        } elseif (preg_match('/^--min-stock=(\d+)$/', $arg, $m)) {
            $opts['min_stock'] = max(1, (int) $m[1]);
        } elseif (preg_match('/^--type=(category|subcategory|all)$/', $arg, $m)) {
            $opts['type'] = $m[1];
        } elseif (preg_match('/^--super=(.+)$/', $arg, $m)) {
            $opts['super'] = trim($m[1], "\"' ");
        } elseif ($arg === '--include-generic') {
            $opts['include_generic'] = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            echo "Usage: php tools/audit_search_quality.php [--top=30] [--min-stock=3] [--type=all|category|subcategory] [--super=\"TV & SAT\"] [--include-generic]\n";
            exit(0);
        }
    }

    return $opts;
}

/**
 * @param PDO $pdo
 * @param int $minStock
 * @param string $super
 * @return array[]
 */
function categoryTargets(PDO $pdo, $minStock, $super)
{
    $params = [(int) $minStock];
    $where  = '';

    if ($super !== '') {
        $where = ' WHERE sg.name = ?';
        $params[] = $super;
    }

    $sql = 'SELECT c.id, c.name, sg.id AS super_id, sg.name AS supercategory, COUNT(*) AS products, SUM(p.stock > 0) AS in_stock
            FROM categories c
            LEFT JOIN supercategories sg ON sg.id = c.super_category_id
            JOIN products p ON p.category_id = c.id
            ' . $where . '
            GROUP BY c.id, c.name, sg.id, sg.name
            HAVING in_stock >= ?
            ORDER BY c.name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_reverse($params));

    $targets = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $targets[] = [
            'type'     => 'category',
            'id'       => (int) $row['id'],
            'name'     => $row['name'],
            'category' => $row['name'],
            'super'    => $row['supercategory'],
            'scope_super_id' => $super !== '' ? (int) $row['super_id'] : null,
            'stock'    => (int) $row['in_stock'],
        ];
    }

    return $targets;
}

/**
 * @param PDO $pdo
 * @param int $minStock
 * @param string $super
 * @return array[]
 */
function subcategoryTargets(PDO $pdo, $minStock, $super)
{
    $params = [(int) $minStock];
    $where  = '';

    if ($super !== '') {
        $where = ' WHERE sg.name = ?';
        $params[] = $super;
    }

    $sql = 'SELECT sc.id, sc.name, c.name AS category, sg.id AS super_id, sg.name AS supercategory, COUNT(*) AS products, SUM(p.stock > 0) AS in_stock
            FROM subcategories sc
            JOIN products p ON p.subcategory_id = sc.id
            LEFT JOIN categories c ON c.id = sc.category_id
            LEFT JOIN supercategories sg ON sg.id = c.super_category_id
            ' . $where . '
            GROUP BY sc.id, sc.name, c.name, sg.id, sg.name
            HAVING in_stock >= ?
            ORDER BY c.name, sc.name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_reverse($params));

    $targets = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $targets[] = [
            'type'        => 'subcategory',
            'id'          => (int) $row['id'],
            'name'        => $row['name'],
            'category'    => $row['category'],
            'subcategory' => $row['name'],
            'super'       => $row['supercategory'],
            'scope_super_id' => $super !== '' ? (int) $row['super_id'] : null,
            'stock'       => (int) $row['in_stock'],
        ];
    }

    return $targets;
}

/**
 * @param array $target
 * @return string[]
 */
function queryVariants(array $target)
{
    $base = trim((string) $target['name']);
    $base = preg_replace('/\s+/u', ' ', $base);

    $queries = [$base, 'pokazi mi ' . $base];

    foreach (aliasesFor($base) as $alias) {
        $queries[] = $alias;
        $queries[] = 'pokazi mi ' . $alias;
    }

    $out = [];
    foreach ($queries as $query) {
        $query = trim((string) $query);
        if ($query !== '') {
            $out[] = $query;
        }
    }

    return array_values(array_unique($out));
}

/**
 * @param string $name
 * @return string[]
 */
function aliasesFor($name)
{
    $norm = Text::normalize($name);

    $aliases = [
        'monitori' => ['monitore', 'monitori', 'monitor'],
        'misevi' => ['miseve', 'miševe', 'gaming miseve', 'mis'],
        'smartwatch' => ['satove', 'satovi', 'pametni sat', 'smart watch', 'rucni sat'],
        'televizori' => ['televizore', 'tv', 'televizor'],
        'laptopi' => ['laptope', 'laptop'],
        'slusalice' => ['slusalice', 'slušalice'],
        'tastature' => ['tastature', 'gaming tastature'],
        'router' => ['routere', 'rutere', 'router'],
        'printeri i skeneri' => ['printere', 'printer', 'skener'],
        'frizideri zamrzivaci vitrine' => ['frizidere', 'frižidere', 'zamrzivace', 'zamrzivače'],
        'masina za pranje vesa' => ['ves masine', 'veš mašine', 'masine za ves'],
        'masina za susenje vesa' => ['susilice', 'sušilice', 'masine za susenje vesa'],
        'masina za pranje vesa susilica' => ['masina za pranje i susenje vesa', 'ves masina susilica'],
        'klima split' => ['klime', 'klima uredjaje', 'klima uređaje'],
        'elektricni romobili' => ['romobile', 'elektricne romobile'],
        'daljinski upravljaci' => ['daljinske', 'daljinski upravljac'],
        'aparati za brijanje' => ['brijace', 'aparat za brijanje'],
        'fen za kosu' => ['fenove', 'fen'],
        'usisavaci' => ['usisivace', 'usisavače', 'usisivac'],
        'bojleri' => ['bojlere', 'bojler'],
        'nape' => ['kuhinjske nape', 'napu'],
        'sporeti' => ['sporete', 'šporete'],
        'stednjaci' => ['stednjake', 'štednjake'],
        'friteze' => ['friteze', 'air fryer'],
    ];

    return isset($aliases[$norm]) ? $aliases[$norm] : [];
}

/**
 * @param string $name
 * @return bool
 */
function isGenericName($name)
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
 * @param ProductSearch $search
 * @param array         $target
 * @param string        $query
 * @return array
 */
function checkTarget(ProductSearch $search, array $target, $query)
{
    $options = ['limit' => 5, 'in_stock_only' => true];
    if (!empty($target['scope_super_id'])) {
        $options['supercategory_id'] = (int) $target['scope_super_id'];
    }

    $results = $search->search($query, $options);

    if ($results === []) {
        return [
            'ok'       => false,
            'severity' => 100,
            'label'    => 'NO RESULTS',
            'query'    => $query,
            'expected' => expectedLabel($target),
            'top'      => '(nothing found)',
            'top5'     => '',
        ];
    }

    $matches = 0;
    foreach ($results as $row) {
        if (matchesTarget($target, $row)) {
            $matches++;
        }
    }

    $topMatches = matchesTarget($target, $results[0]);
    if ($topMatches && $matches >= min(3, count($results))) {
        return ['ok' => true];
    }

    $severity = $topMatches ? 40 : 80;
    if ($matches === 0) {
        $severity = 90;
    }

    return [
        'ok'       => false,
        'severity' => $severity,
        'label'    => $matches === 0 ? 'WRONG BUCKET' : 'WEAK MATCH',
        'query'    => $query,
        'expected' => expectedLabel($target),
        'top'      => productLabel($results[0]),
        'top5'     => topFiveLabel($results),
    ];
}

/**
 * @param array $target
 * @param array $row
 * @return bool
 */
function matchesTarget(array $target, array $row)
{
    if ($target['type'] === 'category') {
        return isset($row['category']) && $row['category'] === $target['category'];
    }

    return isset($row['subcategory']) && $row['subcategory'] === $target['subcategory'];
}

/**
 * @param array $target
 * @return string
 */
function expectedLabel(array $target)
{
    if ($target['type'] === 'category') {
        return 'category: ' . $target['category'] . ' (' . $target['stock'] . ' in stock)';
    }

    return 'subcategory: ' . $target['category'] . ' > ' . $target['subcategory']
         . ' (' . $target['stock'] . ' in stock)';
}

/**
 * @param array $row
 * @return string
 */
function productLabel(array $row)
{
    return $row['name'] . ' | ' . $row['category'] . ' > ' . $row['subcategory'];
}

/**
 * @param array[] $rows
 * @return string
 */
function topFiveLabel(array $rows)
{
    $parts = [];
    foreach ($rows as $row) {
        $parts[] = $row['category'] . ' > ' . $row['subcategory'];
    }

    return implode(' / ', $parts);
}
