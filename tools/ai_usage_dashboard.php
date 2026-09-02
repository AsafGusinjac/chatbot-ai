<?php
/**
 * Generates a self-contained HTML dashboard from logs/ai_usage.log so you
 * can visually check, per message, whether it was answered deterministically
 * (regex/lookup, no API call) or by the real AI (and which model) - the
 * thing lib/ChatService.php's reply() wrapper logs every turn.
 *
 * This does NOT call OpenAI/Digitalis. It only reads the local log
 * file already written by normal chat traffic (dev server, live site,
 * whichever CHATBOT_CONFIG you last ran under) and writes one HTML file -
 * safe and cheap to run as often as you like.
 *
 * Usage:
 *   php tools/ai_usage_dashboard.php
 *   php tools/ai_usage_dashboard.php --tail=2000
 *   php tools/ai_usage_dashboard.php --log=logs/ai_usage.log --out=logs/ai_usage_dashboard.html
 *
 * Then open the printed path in a browser (double-click it - no server
 * needed, it is a plain static file with the data embedded inline).
 *
 * Target: PHP 7.4.
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$opts = parseArgs($argv);

$logPath = __DIR__ . '/../' . $opts['log'];
$outPath = __DIR__ . '/../' . $opts['out'];

if (!is_file($logPath)) {
    fwrite(STDERR, "No log file at {$opts['log']} yet - it is created the first time the chatbot answers a message.\n");
    fwrite(STDERR, "Send a test message through endpoint/chat.php, then run this again.\n");
    exit(1);
}

$entries = [];
$handle  = fopen($logPath, 'r');
if ($handle === false) {
    fwrite(STDERR, "Could not open {$opts['log']}.\n");
    exit(1);
}
while (($line = fgets($handle)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $row = json_decode($line, true);
    if (is_array($row)) {
        $entries[] = $row;
    }
}
fclose($handle);

if ($opts['tail'] > 0 && count($entries) > $opts['tail']) {
    $entries = array_slice($entries, -$opts['tail']);
}

// Newest first - that is almost always what you are debugging right now.
$entries = array_reverse($entries);

$aiCount  = 0;
$mockCount = 0;
foreach ($entries as $e) {
    if (isset($e['path']) && $e['path'] === 'ai') {
        if (isset($e['model']) && $e['model'] === 'MockChatModel') {
            $mockCount++;
        } else {
            $aiCount++;
        }
    }
}
$deterministicCount = count($entries) - $aiCount - $mockCount;

$dataJson  = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$generated = date('Y-m-d H:i:s');
$html = buildHtml($dataJson, count($entries), $aiCount, $mockCount, $deterministicCount, $generated, $opts['log']);

$outDir = dirname($outPath);
if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Could not create {$outDir}.\n");
    exit(1);
}
file_put_contents($outPath, $html);

fwrite(STDOUT, 'Wrote ' . count($entries) . " entries to {$opts['out']}\n");
fwrite(STDOUT, "Open it in a browser: " . str_replace('\\', '/', realpath($outPath)) . "\n");

/**
 * @param string[] $argv
 * @return array{log:string,out:string,tail:int}
 */
function parseArgs(array $argv)
{
    $opts = [
        'log'  => 'logs/ai_usage.log',
        'out'  => 'logs/ai_usage_dashboard.html',
        'tail' => 5000,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (strpos($arg, '--log=') === 0) {
            $opts['log'] = substr($arg, 6);
        } elseif (strpos($arg, '--out=') === 0) {
            $opts['out'] = substr($arg, 6);
        } elseif (strpos($arg, '--tail=') === 0) {
            $opts['tail'] = (int) substr($arg, 7);
        }
    }

    return $opts;
}

/**
 * @param string $dataJson
 * @param int    $total
 * @param int    $aiCount
 * @param int    $mockCount
 * @param int    $deterministicCount
 * @param string $generated
 * @param string $logRelPath
 * @return string
 */
function buildHtml($dataJson, $total, $aiCount, $mockCount, $deterministicCount, $generated, $logRelPath)
{
    $logRelPathEsc = htmlspecialchars($logRelPath, ENT_QUOTES, 'UTF-8');
    $generatedEsc  = htmlspecialchars($generated, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>AI usage log</title>
<style>
:root {
    --bg: #0b0f14;
    --bg-raised: #121821;
    --bg-hover: #182130;
    --border: #232d3b;
    --text: #dbe4ef;
    --text-dim: #8393a8;
    --text-faint: #56637a;
    --accent-ai: #4fd1c5;
    --accent-ai-bg: rgba(79, 209, 197, 0.12);
    --accent-mock: #f2b155;
    --accent-mock-bg: rgba(242, 177, 85, 0.12);
    --accent-det: #7c8cf8;
    --accent-det-bg: rgba(124, 140, 248, 0.12);
    --focus: #4fd1c5;
    --mono: ui-monospace, "Cascadia Code", "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
    --sans: -apple-system, "Segoe UI", system-ui, Roboto, sans-serif;
}
@media (prefers-color-scheme: light) {
    :root {
        --bg: #f4f6f9;
        --bg-raised: #ffffff;
        --bg-hover: #eef2f7;
        --border: #dde3ec;
        --text: #1c2733;
        --text-dim: #5a6b80;
        --text-faint: #8a97a8;
        --accent-ai: #0d9488;
        --accent-ai-bg: rgba(13, 148, 136, 0.1);
        --accent-mock: #b45309;
        --accent-mock-bg: rgba(180, 83, 9, 0.1);
        --accent-det: #4338ca;
        --accent-det-bg: rgba(67, 56, 202, 0.08);
    }
}
* { box-sizing: border-box; }
html, body {
    margin: 0;
    background: var(--bg);
    color: var(--text);
    font-family: var(--sans);
    -webkit-font-smoothing: antialiased;
}
body { padding: 24px 28px 60px; }
h1 { font-size: 1.35rem; font-weight: 650; margin: 0 0 2px; letter-spacing: -0.01em; }
.subtitle { color: var(--text-dim); font-size: 0.85rem; margin: 0 0 20px; }
.subtitle code { font-family: var(--mono); background: var(--bg-raised); padding: 1px 5px; border-radius: 4px; border: 1px solid var(--border); }

.stats { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px; }
.stat {
    background: var(--bg-raised);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 10px 16px;
    min-width: 108px;
}
.stat .n { font-family: var(--mono); font-size: 1.3rem; font-weight: 600; font-variant-numeric: tabular-nums; }
.stat .l { font-size: 0.72rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.04em; margin-top: 2px; }
.stat.ai .n { color: var(--accent-ai); }
.stat.mock .n { color: var(--accent-mock); }
.stat.det .n { color: var(--accent-det); }

.controls {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
    margin-bottom: 14px;
    position: sticky;
    top: 0;
    background: var(--bg);
    padding: 10px 0;
    z-index: 5;
}
.controls input[type="search"], .controls select {
    background: var(--bg-raised);
    border: 1px solid var(--border);
    color: var(--text);
    border-radius: 8px;
    padding: 7px 11px;
    font-size: 0.85rem;
    font-family: var(--sans);
}
.controls input[type="search"] { flex: 1; min-width: 220px; }
.controls input[type="search"]:focus, .controls select:focus, button:focus-visible {
    outline: 2px solid var(--focus);
    outline-offset: 1px;
}
.pathfilter { display: flex; gap: 6px; }
.pathfilter button {
    background: var(--bg-raised);
    border: 1px solid var(--border);
    color: var(--text-dim);
    border-radius: 8px;
    padding: 7px 12px;
    font-size: 0.8rem;
    cursor: pointer;
    font-family: var(--sans);
}
.pathfilter button.active { color: var(--text); border-color: var(--text-faint); background: var(--bg-hover); }
.count { color: var(--text-faint); font-size: 0.8rem; white-space: nowrap; }

.table-scroll { overflow-x: auto; }
table { width: 100%; min-width: 900px; border-collapse: collapse; font-size: 0.83rem; }
thead th {
    text-align: left;
    font-weight: 600;
    color: var(--text-dim);
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 8px 10px;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
tbody tr { border-bottom: 1px solid var(--border); cursor: pointer; }
tbody tr:hover { background: var(--bg-hover); }
tbody td { padding: 9px 10px; vertical-align: top; }
.col-time { font-family: var(--mono); color: var(--text-faint); white-space: nowrap; font-size: 0.78rem; }
.col-site { color: var(--text-dim); white-space: nowrap; max-width: 160px; overflow: hidden; text-overflow: ellipsis; }
.col-msg, .col-reply { max-width: 340px; }
.snippet { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.35; }
tr.expanded .snippet { -webkit-line-clamp: unset; }
.col-dur { font-family: var(--mono); color: var(--text-faint); white-space: nowrap; text-align: right; font-variant-numeric: tabular-nums; }
.col-products { font-family: var(--mono); text-align: right; color: var(--text-dim); }

.badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 9px;
    border-radius: 100px;
    font-size: 0.72rem;
    font-weight: 600;
    white-space: nowrap;
}
.badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.badge.ai { color: var(--accent-ai); background: var(--accent-ai-bg); }
.badge.mock { color: var(--accent-mock); background: var(--accent-mock-bg); }
.badge.det { color: var(--accent-det); background: var(--accent-det-bg); }
.model { display: block; color: var(--text-faint); font-size: 0.7rem; font-family: var(--mono); margin-top: 2px; }

.empty { text-align: center; padding: 60px 20px; color: var(--text-faint); }
::-webkit-scrollbar { height: 8px; width: 8px; }
::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }
</style>
</head>
<body>
<h1>AI usage log</h1>
<p class="subtitle">Generated {$generatedEsc} from <code>{$logRelPathEsc}</code> — re-run <code>php tools/ai_usage_dashboard.php</code> after new traffic to refresh. Click a row to expand the full message and reply.</p>

<div class="stats">
    <div class="stat"><div class="n" id="stat-total">{$total}</div><div class="l">Total turns</div></div>
    <div class="stat ai"><div class="n" id="stat-ai">{$aiCount}</div><div class="l">Real AI</div></div>
    <div class="stat mock"><div class="n" id="stat-mock">{$mockCount}</div><div class="l">Mock AI</div></div>
    <div class="stat det"><div class="n" id="stat-det">{$deterministicCount}</div><div class="l">Deterministic</div></div>
</div>

<div class="controls">
    <input type="search" id="search" placeholder="Filter by message, reply, site, or conversation id...">
    <select id="site-filter"><option value="">All sites</option></select>
    <div class="pathfilter" id="path-filter">
        <button data-path="" class="active">All</button>
        <button data-path="ai">AI</button>
        <button data-path="mock">Mock</button>
        <button data-path="deterministic">Deterministic</button>
    </div>
    <span class="count" id="visible-count"></span>
</div>

<div class="table-scroll">
<table>
    <thead>
        <tr>
            <th>Time</th>
            <th>Site</th>
            <th>Path</th>
            <th>Message</th>
            <th>Reply</th>
            <th>Products</th>
            <th>Duration</th>
        </tr>
    </thead>
    <tbody id="rows"></tbody>
</table>
</div>
<div class="empty" id="empty-state" style="display:none">No log entries yet. Send a test message through the chatbot, then re-run the generator.</div>

<script>
var DATA = {$dataJson};

function pathInfo(entry) {
    if (entry.path === 'ai' && entry.model === 'MockChatModel') {
        return { key: 'mock', label: 'Mock', cls: 'mock' };
    }
    if (entry.path === 'ai') {
        return { key: 'ai', label: 'AI', cls: 'ai' };
    }
    return { key: 'deterministic', label: 'Deterministic', cls: 'det' };
}

function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}

function fmtTime(iso) {
    if (!iso) return '';
    var d = new Date(iso);
    if (isNaN(d.getTime())) return iso;
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
}

function siteLabel(site) {
    if (!site) return '';
    return site.replace(/^https?:\\/\\//, '');
}

var sites = [];
DATA.forEach(function (e) {
    if (e.site && sites.indexOf(e.site) === -1) sites.push(e.site);
});
sites.sort();
var siteSelect = document.getElementById('site-filter');
sites.forEach(function (s) {
    var opt = document.createElement('option');
    opt.value = s;
    opt.textContent = siteLabel(s);
    siteSelect.appendChild(opt);
});

var state = { path: '', site: '', q: '' };

function render() {
    var tbody = document.getElementById('rows');
    tbody.innerHTML = '';
    var q = state.q.trim().toLowerCase();
    var shown = 0;

    DATA.forEach(function (entry) {
        var info = pathInfo(entry);
        if (state.path && info.key !== state.path) return;
        if (state.site && entry.site !== state.site) return;
        if (q) {
            var hay = [entry.message, entry.reply, entry.site, entry.conversation_id, entry.user_id]
                .join(' ').toLowerCase();
            if (hay.indexOf(q) === -1) return;
        }
        shown++;

        var tr = document.createElement('tr');
        var modelLine = (info.key !== 'deterministic' && entry.model) ? '<span class="model">' + esc(entry.model) + '</span>' : '';
        tr.innerHTML =
            '<td class="col-time">' + esc(fmtTime(entry.ts)) + '</td>' +
            '<td class="col-site">' + esc(siteLabel(entry.site)) + '</td>' +
            '<td><span class="badge ' + info.cls + '">' + info.label + '</span>' + modelLine + '</td>' +
            '<td class="col-msg"><div class="snippet">' + esc(entry.message) + '</div></td>' +
            '<td class="col-reply"><div class="snippet">' + esc(entry.reply) + '</div></td>' +
            '<td class="col-products">' + esc(entry.products_count != null ? entry.products_count : '') + '</td>' +
            '<td class="col-dur">' + (entry.duration_ms != null ? entry.duration_ms + ' ms' : '') + '</td>';
        tr.addEventListener('click', function () {
            tr.classList.toggle('expanded');
        });
        tbody.appendChild(tr);
    });

    document.getElementById('visible-count').textContent = shown + ' / ' + DATA.length + ' shown';
    document.getElementById('empty-state').style.display = DATA.length === 0 ? 'block' : 'none';
}

document.getElementById('search').addEventListener('input', function (e) {
    state.q = e.target.value;
    render();
});
document.getElementById('site-filter').addEventListener('change', function (e) {
    state.site = e.target.value;
    render();
});
document.getElementById('path-filter').addEventListener('click', function (e) {
    var btn = e.target.closest('button');
    if (!btn) return;
    state.path = btn.getAttribute('data-path');
    Array.prototype.forEach.call(document.querySelectorAll('#path-filter button'), function (b) {
        b.classList.toggle('active', b === btn);
    });
    render();
});

render();
</script>
</body>
</html>
HTML;
}
