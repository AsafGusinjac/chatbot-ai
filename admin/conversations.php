<?php
/**
 * Lightweight production/debug dashboard for chatbot conversations.
 *
 * Target: PHP 7.4.
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/RateLimiter.php';

requireAdminAccess();

$pdo = db();
ensureDashboardTables($pdo);

$filters = [
    'webshop' => isset($_GET['webshop']) ? strtolower(trim((string) $_GET['webshop'])) : '',
    'rating'  => isset($_GET['rating']) ? (int) $_GET['rating'] : 0,
    'path'    => isset($_GET['path']) ? strtolower(trim((string) $_GET['path'])) : '',
    'ip'      => isset($_GET['ip']) ? trim((string) $_GET['ip']) : '',
    'q'       => isset($_GET['q']) ? trim((string) $_GET['q']) : '',
];

$conversationId = isset($_GET['conversation_id']) ? (int) $_GET['conversation_id'] : 0;

if ($conversationId > 0) {
    renderConversation($pdo, $conversationId, $filters);
    exit;
}

renderDashboard($pdo, $filters);

/**
 * @return void
 */
function requireAdminAccess()
{
    $token = (string) config_get('admin_token', '');
    $allowedIps = config_get('admin_allowed_ips', []);
    $ip = RateLimiter::clientIp(config_get('trusted_proxies', []));

    $ipAllowed = is_array($allowedIps) && in_array($ip, $allowedIps, true);
    $presented = '';
    if (isset($_GET['token'])) {
        $presented = (string) $_GET['token'];
    } elseif (!empty($_SERVER['HTTP_X_ADMIN_TOKEN'])) {
        $presented = (string) $_SERVER['HTTP_X_ADMIN_TOKEN'];
    }

    if ($token !== '' && hash_equals($token, $presented)) {
        return;
    }
    if ($token === '' && $ipAllowed) {
        return;
    }
    if ($token !== '' && $ipAllowed && $presented === '') {
        return;
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit;
}

/**
 * @param PDO $pdo
 * @return void
 */
function ensureDashboardTables(PDO $pdo)
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS conversation_feedback (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            conversation_id BIGINT UNSIGNED NULL,
            channel         VARCHAR(32) NOT NULL DEFAULT 'web',
            external_id     VARCHAR(191) NOT NULL DEFAULT '',
            webshop         VARCHAR(32) NOT NULL DEFAULT '',
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

    ensureColumn($pdo, 'conversations', 'webshop', "VARCHAR(32) NOT NULL DEFAULT ''");
    ensureColumn($pdo, 'conversations', 'client_ip', "VARCHAR(64) NOT NULL DEFAULT ''");
    ensureColumn($pdo, 'conversations', 'customer_id', "VARCHAR(191) NOT NULL DEFAULT ''");
    ensureColumn($pdo, 'conversations', 'customer_name', "VARCHAR(191) NOT NULL DEFAULT ''");
    ensureColumn($pdo, 'conversations', 'wholesale_hint', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'conversation_feedback', 'webshop', "VARCHAR(32) NOT NULL DEFAULT ''");
    ensureColumn($pdo, 'conversation_feedback', 'customer_id', "VARCHAR(191) NULL");
    ensureColumn($pdo, 'conversation_feedback', 'customer_name', "VARCHAR(191) NULL");
    ensureColumn($pdo, 'conversation_feedback', 'wholesale_hint', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensureIndex($pdo, 'conversations', 'idx_webshop', 'webshop');
    ensureIndex($pdo, 'conversations', 'idx_client_ip', 'client_ip');
    ensureIndex($pdo, 'conversation_feedback', 'idx_webshop', 'webshop');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS chat_turn_logs (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            conversation_id BIGINT UNSIGNED NULL,
            channel         VARCHAR(32) NOT NULL DEFAULT '',
            external_id     VARCHAR(191) NOT NULL DEFAULT '',
            webshop         VARCHAR(32) NOT NULL DEFAULT '',
            client_ip       VARCHAR(64) NOT NULL DEFAULT '',
            customer_id     VARCHAR(191) NOT NULL DEFAULT '',
            customer_name   VARCHAR(191) NOT NULL DEFAULT '',
            wholesale_hint  TINYINT(1) NOT NULL DEFAULT 0,
            path            VARCHAR(32) NOT NULL DEFAULT '',
            model           VARCHAR(128) NULL,
            duration_ms     INT UNSIGNED NOT NULL DEFAULT 0,
            products_count  INT UNSIGNED NOT NULL DEFAULT 0,
            user_message    TEXT NOT NULL,
            assistant_reply MEDIUMTEXT NOT NULL,
            created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_conversation (conversation_id),
            KEY idx_created (created_at),
            KEY idx_webshop (webshop),
            KEY idx_path (path),
            KEY idx_client_ip (client_ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensureColumn(PDO $pdo, $table, $column, $definition)
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
        if ($stmt !== false && $stmt->fetch() !== false) {
            return;
        }
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    } catch (Exception $e) {
        error_log('admin/conversations.php column migration failed: ' . $table . '.' . $column . ' — ' . $e->getMessage());
    }
}

function ensureIndex(PDO $pdo, $table, $index, $column)
{
    try {
        $stmt = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name = " . $pdo->quote($index));
        if ($stmt !== false && $stmt->fetch() !== false) {
            return;
        }
        $pdo->exec("ALTER TABLE `{$table}` ADD KEY `{$index}` (`{$column}`)");
    } catch (Exception $e) {
        error_log('admin/conversations.php index migration failed: ' . $table . '.' . $index . ' — ' . $e->getMessage());
    }
}

/**
 * @param PDO   $pdo
 * @param array $filters
 * @return void
 */
function renderDashboard(PDO $pdo, array $filters)
{
    $where = [];
    $params = [];
    $where[] = '(t.id IS NOT NULL OR f.id IS NOT NULL OR EXISTS (SELECT 1 FROM messages mx WHERE mx.conversation_id = c.id))';

    if (in_array($filters['webshop'], ['digitalis', 'dstore', 'zed', 'optibox'], true)) {
        $where[] = 'COALESCE(NULLIF(t.webshop, ""), NULLIF(c.webshop, ""), f.webshop, "") = ?';
        $params[] = $filters['webshop'];
    }
    if ($filters['rating'] >= 1 && $filters['rating'] <= 5) {
        $where[] = 'f.rating = ?';
        $params[] = $filters['rating'];
    }
    if (in_array($filters['path'], ['ai', 'deterministic', 'cached'], true)) {
        $where[] = 't.path = ?';
        $params[] = $filters['path'];
    }
    if ($filters['ip'] !== '') {
        $where[] = 'COALESCE(NULLIF(t.client_ip, ""), c.client_ip, "") = ?';
        $params[] = $filters['ip'];
    }
    if ($filters['q'] !== '') {
        $where[] = '(t.user_message LIKE ? OR t.assistant_reply LIKE ? OR f.comment LIKE ?)';
        $needle = '%' . $filters['q'] . '%';
        $params[] = $needle;
        $params[] = $needle;
        $params[] = $needle;
    }

    $sqlWhere = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
    $sql = "
        SELECT
            c.id,
            c.channel,
            c.external_id,
            COALESCE(NULLIF(MAX(t.webshop), ''), NULLIF(c.webshop, ''), NULLIF(MAX(f.webshop), ''), '') AS webshop,
            COALESCE(NULLIF(MAX(t.client_ip), ''), NULLIF(c.client_ip, ''), '') AS client_ip,
            COALESCE(NULLIF(MAX(t.customer_name), ''), NULLIF(c.customer_name, ''), '') AS customer_name,
            MAX(c.wholesale_hint) AS wholesale_hint,
            COALESCE(MAX(t.created_at), c.last_message_at, c.created_at) AS last_at,
            COUNT(t.id) AS turns,
            SUM(CASE WHEN t.path = 'ai' THEN 1 ELSE 0 END) AS ai_turns,
            SUM(CASE WHEN t.path = 'deterministic' THEN 1 ELSE 0 END) AS deterministic_turns,
            SUM(CASE WHEN t.path = 'cached' THEN 1 ELSE 0 END) AS cached_turns,
            MAX(f.rating) AS rating,
            MAX(f.comment) AS feedback_comment,
            (SELECT COUNT(*) FROM messages mu WHERE mu.conversation_id = c.id AND mu.role = 'user') AS user_messages,
            (SELECT COUNT(*) FROM messages ma WHERE ma.conversation_id = c.id AND ma.role = 'assistant') AS assistant_messages,
            COALESCE(
                SUBSTRING_INDEX(GROUP_CONCAT(t.user_message ORDER BY t.id DESC SEPARATOR '\n---\n'), '\n---\n', 1),
                (SELECT ml.content FROM messages ml WHERE ml.conversation_id = c.id AND ml.role = 'user' ORDER BY ml.id DESC LIMIT 1),
                ''
            ) AS last_user_message,
            COALESCE(
                SUBSTRING_INDEX(GROUP_CONCAT(t.assistant_reply ORDER BY t.id DESC SEPARATOR '\n---\n'), '\n---\n', 1),
                (SELECT ml.content FROM messages ml WHERE ml.conversation_id = c.id AND ml.role = 'assistant' ORDER BY ml.id DESC LIMIT 1),
                ''
            ) AS last_assistant_reply
        FROM conversations c
        LEFT JOIN chat_turn_logs t ON t.conversation_id = c.id
        LEFT JOIN conversation_feedback f ON f.conversation_id = c.id
        {$sqlWhere}
        GROUP BY c.id, c.channel, c.external_id, c.webshop, c.client_ip, c.customer_name, c.wholesale_hint, c.created_at, c.last_message_at
        ORDER BY last_at DESC
        LIMIT 300";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $stats = dashboardStats($pdo);
    pageStart('Chatbot razgovori');
    echo '<h1>Chatbot razgovori</h1>';
    echo '<div class="stats">';
    statBox('Razgovori', (string) $stats['conversations']);
    statBox('Turnovi', (string) $stats['turns']);
    statBox('AI pozivi', (string) $stats['ai_turns']);
    statBox('Ocjene', (string) $stats['ratings']);
    echo '</div>';
    renderFilters($filters);

    echo '<table><thead><tr>';
    echo '<th>Vrijeme</th><th>Webshop</th><th>Kanal/IP</th><th>Poruke</th><th>AI</th><th>Ocjena</th><th>Zadnji tok</th><th></th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        echo '<tr>';
        echo '<td>' . e((string) $row['last_at']) . '</td>';
        echo '<td><span class="pill">' . e((string) $row['webshop']) . '</span></td>';
        echo '<td>' . e((string) $row['channel']) . '<br><small>' . e((string) $row['client_ip']) . '</small></td>';
        echo '<td>' . (int) $row['user_messages'] . ' korisnik<br><small>' . (int) $row['assistant_messages'] . ' bot</small></td>';
        echo '<td>' . (int) $row['ai_turns'] . ' AI<br><small>' . (int) $row['deterministic_turns'] . ' local, ' . (int) $row['cached_turns'] . ' cache</small></td>';
        echo '<td>' . ratingCell($row) . '</td>';
        echo '<td><div class="msg user">' . e(shortText((string) $row['last_user_message'], 180)) . '</div><div class="msg bot">' . e(shortText((string) $row['last_assistant_reply'], 220)) . '</div></td>';
        echo '<td><a class="btn" href="?conversation_id=' . $id . tokenQuery() . '">Otvori</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    pageEnd();
}

/**
 * @param PDO   $pdo
 * @param int   $conversationId
 * @param array $filters
 * @return void
 */
function renderConversation(PDO $pdo, $conversationId, array $filters)
{
    $stmt = $pdo->prepare('SELECT * FROM conversations WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $conversationId]);
    $conversation = $stmt->fetch();
    if (!$conversation) {
        http_response_code(404);
        echo 'Conversation not found';
        return;
    }

    $turns = $pdo->prepare('SELECT * FROM chat_turn_logs WHERE conversation_id = ? ORDER BY id ASC');
    $turns->execute([(int) $conversationId]);
    $turnRows = $turns->fetchAll();

    $messages = $pdo->prepare('SELECT * FROM messages WHERE conversation_id = ? ORDER BY id ASC');
    $messages->execute([(int) $conversationId]);
    $messageRows = $messages->fetchAll();

    $feedback = $pdo->prepare('SELECT * FROM conversation_feedback WHERE conversation_id = ? ORDER BY id DESC');
    $feedback->execute([(int) $conversationId]);
    $feedbackRows = $feedback->fetchAll();

    pageStart('Razgovor #' . $conversationId);
    echo '<p><a class="btn secondary" href="?' . http_build_query(array_filter($filters, 'filterNonEmpty')) . tokenQuery(false) . '">Nazad</a></p>';
    echo '<h1>Razgovor #' . (int) $conversationId . '</h1>';
    echo '<div class="meta">';
    metaItem('Kanal', (string) $conversation['channel']);
    metaItem('Webshop', isset($conversation['webshop']) ? (string) $conversation['webshop'] : '');
    metaItem('IP', isset($conversation['client_ip']) ? (string) $conversation['client_ip'] : '');
    metaItem('Kupac', isset($conversation['customer_name']) ? (string) $conversation['customer_name'] : '');
    metaItem('Logged/VP', !empty($conversation['wholesale_hint']) ? 'da' : 'ne');
    echo '</div>';

    if ($feedbackRows !== []) {
        echo '<h2>Ocjene</h2>';
        foreach ($feedbackRows as $f) {
            echo '<div class="card"><strong>' . (int) $f['rating'] . '/5</strong> ';
            echo '<small>' . e((string) $f['created_at']) . ' · ' . e((string) $f['webshop']) . '</small>';
            if ((string) $f['comment'] !== '') {
                echo '<p>' . nl2br(e((string) $f['comment'])) . '</p>';
            }
            echo '</div>';
        }
    }

    echo '<h2>Turnovi</h2>';
    if ($turnRows !== []) {
        foreach ($turnRows as $t) {
            echo '<div class="turn">';
            echo '<div class="turn-head">' . e((string) $t['created_at']) . ' · <span class="pill">' . e((string) $t['path']) . '</span> · ' . e((string) $t['model']) . ' · ' . (int) $t['duration_ms'] . ' ms</div>';
            echo '<div class="bubble user">' . nl2br(e((string) $t['user_message'])) . '</div>';
            echo '<div class="bubble bot">' . nl2br(e((string) $t['assistant_reply'])) . '</div>';
            echo '</div>';
        }
    } else {
        echo '<p>Nema turn logova za ovaj stariji razgovor. Prikazujem sirove poruke.</p>';
        foreach ($messageRows as $m) {
            echo '<div class="bubble ' . e((string) $m['role']) . '">' . nl2br(e((string) $m['content'])) . '</div>';
        }
    }
    pageEnd();
}

/**
 * @param PDO $pdo
 * @return array
 */
function dashboardStats(PDO $pdo)
{
    return [
        'conversations' => (int) $pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn(),
        'turns' => (int) $pdo->query('SELECT COUNT(*) FROM chat_turn_logs')->fetchColumn(),
        'ai_turns' => (int) $pdo->query("SELECT COUNT(*) FROM chat_turn_logs WHERE path = 'ai'")->fetchColumn(),
        'ratings' => (int) $pdo->query('SELECT COUNT(*) FROM conversation_feedback')->fetchColumn(),
    ];
}

function renderFilters(array $filters)
{
    echo '<form class="filters" method="get">';
    echo tokenField();
    selectField('webshop', $filters['webshop'], ['' => 'Svi webshopovi', 'digitalis' => 'Digitalis', 'dstore' => 'D-Store', 'zed' => 'Zed', 'optibox' => 'Optibox']);
    selectField('path', $filters['path'], ['' => 'Svi odgovori', 'ai' => 'Samo AI', 'deterministic' => 'Lokalni odgovori', 'cached' => 'Cache']);
    selectField('rating', (string) $filters['rating'], ['0' => 'Sve ocjene', '1' => '1 zvjezdica', '2' => '2 zvjezdice', '3' => '3 zvjezdice', '4' => '4 zvjezdice', '5' => '5 zvjezdica']);
    echo '<input name="ip" placeholder="IP adresa" value="' . e($filters['ip']) . '">';
    echo '<input name="q" placeholder="Traži tekst..." value="' . e($filters['q']) . '">';
    echo '<button>Filtriraj</button>';
    echo '</form>';
}

function selectField($name, $value, array $options)
{
    echo '<select name="' . e($name) . '">';
    foreach ($options as $key => $label) {
        echo '<option value="' . e((string) $key) . '"' . ((string) $key === (string) $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select>';
}

function statBox($label, $value)
{
    echo '<div class="stat"><span>' . e($label) . '</span><strong>' . e($value) . '</strong></div>';
}

function metaItem($label, $value)
{
    echo '<div class="stat"><span>' . e($label) . '</span><strong>' . e($value !== '' ? $value : '-') . '</strong></div>';
}

function ratingCell(array $row)
{
    if (empty($row['rating'])) {
        return '<small>-</small>';
    }
    $out = '<strong>' . (int) $row['rating'] . '/5</strong>';
    if (!empty($row['feedback_comment'])) {
        $out .= '<br><small>' . e(shortText((string) $row['feedback_comment'], 80)) . '</small>';
    }
    return $out;
}

function tokenField()
{
    return isset($_GET['token']) ? '<input type="hidden" name="token" value="' . e((string) $_GET['token']) . '">' : '';
}

function tokenQuery($withAmp = true)
{
    if (!isset($_GET['token'])) {
        return '';
    }
    return ($withAmp ? '&' : '') . 'token=' . rawurlencode((string) $_GET['token']);
}

function filterNonEmpty($value)
{
    return $value !== '' && $value !== 0 && $value !== '0';
}

function shortText($text, $max)
{
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
    return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function pageStart($title)
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="bs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . e($title) . '</title><style>
        :root{font-family:Arial,Helvetica,sans-serif;color:#17202a;background:#f5f7fb}
        body{margin:0;padding:24px}
        h1{margin:0 0 18px;font-size:26px}
        h2{margin:24px 0 12px;font-size:18px}
        a{color:#c83a00}
        .stats,.meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:16px}
        .stat,.card,.turn,table{background:#fff;border:1px solid #dde3ee;border-radius:8px;box-shadow:0 1px 2px rgba(15,23,42,.04)}
        .stat{padding:12px}.stat span{display:block;color:#64748b;font-size:12px}.stat strong{font-size:20px}
        .filters{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px}.filters input,.filters select{height:38px;border:1px solid #cbd5e1;border-radius:7px;padding:0 10px;background:#fff}.filters button,.btn{height:38px;border:0;border-radius:7px;background:#d43f00;color:#fff;padding:0 13px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center}.btn.secondary{background:#475569}
        table{width:100%;border-collapse:separate;border-spacing:0;overflow:hidden}th,td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top;font-size:14px}th{background:#eef2f7;font-size:12px;color:#475569;text-transform:uppercase}tr:last-child td{border-bottom:0}
        small{color:#64748b}.pill{display:inline-block;border:1px solid #f0a37f;background:#fff3ed;color:#a63200;border-radius:999px;padding:2px 8px;font-size:12px}
        .msg,.bubble{white-space:pre-wrap;line-height:1.45}.msg.user{font-weight:700;margin-bottom:6px}.msg.bot{color:#334155}
        .turn{padding:14px;margin-bottom:14px}.turn-head{color:#64748b;font-size:12px;margin-bottom:10px}
        .bubble{max-width:900px;padding:12px;border-radius:10px;margin:8px 0}.bubble.user{background:#fff3ed;margin-left:auto}.bubble.bot,.bubble.assistant{background:#eef2f7}
        .card{padding:12px;margin-bottom:10px}
        @media(max-width:850px){body{padding:12px}.stats,.meta{grid-template-columns:1fr 1fr}table{font-size:12px}.filters input,.filters select{width:100%}}
    </style></head><body>';
}

function pageEnd()
{
    echo '</body></html>';
}
