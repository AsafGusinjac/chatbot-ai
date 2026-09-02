<?php
/**
 * Shows the questions the bot could not answer.
 *
 * Every time the assistant falls back to "I'm not certain, call us" it is
 * telling you something is missing from prompts/system_prompt.txt. This lists
 * those moments, most recent first, so the FAQ gets built from what customers
 * actually ask rather than from guesswork.
 *
 * Run this weekly once the bot is live. Each recurring question you add to the
 * prompt is one less phone call for your colleagues.
 *
 * Run:  C:\xampp\php\php.exe tools\faq_gaps.php [days]
 *
 * Target: PHP 7.4.
 */

require_once __DIR__ . '/../config.php';

$days = isset($argv[1]) ? max(1, (int) $argv[1]) : 30;

// Phrases the assistant uses when it is deflecting rather than answering.
// Extend this if you change the wording in the system prompt.
$deflections = [
    'nisam siguran', 'nisam sigurna', 'nisam pronasao', 'nisam pronašao',
    'nazovite', 'javite se', 'kontaktirajte', 'ne mogu potvrditi',
    'not certain', 'not sure', 'could not find', 'contact our team',
    '0800 22 432', 'info@digitalis.ba',
];

$pdo = db();

$where = [];
foreach ($deflections as $i => $phrase) {
    $where[] = "a.content LIKE :p{$i}";
}

$sql = "
    SELECT c.channel,
           q.content  AS question,
           a.content  AS answer,
           a.created_at
    FROM messages a
    JOIN conversations c ON c.id = a.conversation_id
    JOIN messages q ON q.conversation_id = a.conversation_id
                   AND q.role = 'user'
                   AND q.id = (SELECT MAX(id) FROM messages m
                               WHERE m.conversation_id = a.conversation_id
                                 AND m.role = 'user'
                                 AND m.id < a.id)
    WHERE a.role = 'assistant'
      AND a.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
      AND (" . implode(' OR ', $where) . ")
    ORDER BY a.created_at DESC
    LIMIT 200
";

$stmt = $pdo->prepare($sql);
foreach ($deflections as $i => $phrase) {
    $stmt->bindValue(":p{$i}", '%' . $phrase . '%');
}
$stmt->execute();

$rows = $stmt->fetchAll();

echo "Questions the bot could not answer (last {$days} days)\n";
echo str_repeat('=', 72), "\n\n";

if ($rows === []) {
    echo "None found. Either the prompt covers everything, or the bot has not\n";
    echo "handled many conversations yet.\n";
    exit(0);
}

// Group near-identical questions so repeats stand out.
$grouped = [];
foreach ($rows as $row) {
    $key = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N} ]+/u', '', $row['question'])));
    if (!isset($grouped[$key])) {
        $grouped[$key] = ['question' => $row['question'], 'count' => 0, 'channels' => []];
    }
    $grouped[$key]['count']++;
    $grouped[$key]['channels'][$row['channel']] = true;
}

uasort($grouped, function ($a, $b) {
    return $b['count'] - $a['count'];
});

$rank = 1;
foreach ($grouped as $g) {
    printf("%2d. [asked %dx | %s]\n    %s\n\n",
        $rank++,
        $g['count'],
        implode(', ', array_keys($g['channels'])),
        $g['question']
    );
}

echo str_repeat('-', 72), "\n";
echo count($grouped), " distinct question(s), ", count($rows), " occurrence(s).\n";
echo "Add the frequent ones to prompts/system_prompt.txt.\n";
