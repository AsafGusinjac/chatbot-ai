<?php
/**
 * Conversation history in MySQL, keyed by channel + external user id.
 *
 * PHP sessions are useless here: Make.com does not carry cookies, and the same
 * customer may reach us from Viber today and WhatsApp tomorrow. Each channel's
 * own user identifier (Viber user id, WhatsApp phone number, Messenger PSID)
 * is the stable key.
 *
 * Target: PHP 7.4.
 */
class ConversationStore
{
    /** Channels we accept. Anything else is rejected rather than stored. */
    const CHANNELS = ['viber', 'whatsapp', 'messenger', 'web', 'olx', 'test'];

    /** @var PDO */
    private $pdo;

    /** @var bool|null */
    private $lastProductIdsSupported = null;

    /** @var bool|null */
    private $selectedProductIdSupported = null;

    /** @var array<string,bool> */
    private $metadataColumns = [];

    /** @param PDO $pdo */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Find or create the conversation for this channel + user.
     *
     * @param string $channel
     * @param string $externalId
     * @return int Conversation id.
     */
    public function getOrCreate($channel, $externalId)
    {
        $channel    = strtolower(trim($channel));
        $externalId = trim($externalId);

        if (!in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException("Unknown channel: {$channel}");
        }
        if ($externalId === '') {
            throw new InvalidArgumentException('Empty external id.');
        }
        if (mb_strlen($externalId) > 191) {
            // The unique index is on a 191-char column; hash anything longer so
            // exotic ids cannot silently collide after truncation.
            $externalId = 'h_' . sha1($externalId);
        }

        $select = $this->pdo->prepare(
            'SELECT id FROM conversations WHERE channel = ? AND external_id = ? LIMIT 1'
        );
        $select->execute([$channel, $externalId]);
        $id = $select->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        // INSERT IGNORE rather than a plain INSERT: two messages arriving at
        // once would otherwise race between the SELECT above and here.
        $this->pdo->prepare(
            'INSERT IGNORE INTO conversations (channel, external_id) VALUES (?, ?)'
        )->execute([$channel, $externalId]);

        $select->execute([$channel, $externalId]);
        return (int) $select->fetchColumn();
    }

    /**
     * Recent history, oldest first, in chat-model message format.
     *
     * @param int $conversationId
     * @param int $limit Maximum messages to return.
     * @return array[]
     */
    public function history($conversationId, $limit = 20)
    {
        $limit = max(2, min(100, (int) $limit));

        // Newest N, then flip — otherwise a long conversation returns its
        // opening messages and drops everything recent.
        $stmt = $this->pdo->prepare(
            'SELECT role, content FROM messages
             WHERE conversation_id = ?
             ORDER BY id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([(int) $conversationId]);

        $rows = array_reverse($stmt->fetchAll());

        $messages = [];
        foreach ($rows as $row) {
            $messages[] = ['role' => $row['role'], 'content' => $row['content']];
        }

        // Chat-model APIs behave best when the conversation opens with a
        // user message.
        while ($messages !== [] && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }

        return $messages;
    }

    /**
     * How many messages of a given role this conversation has, ever.
     *
     * @param int    $conversationId
     * @param string $role 'user' or 'assistant'
     * @return int
     */
    public function messageCountByRole($conversationId, $role)
    {
        if (!in_array($role, ['user', 'assistant'], true)) {
            throw new InvalidArgumentException("Bad role: {$role}");
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM messages WHERE conversation_id = ? AND role = ?'
        );
        $stmt->execute([(int) $conversationId, $role]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * How many assistant replies this conversation has, ever - used to cap
     * completed bot answers rather than customer attempts.
     *
     * @param int $conversationId
     * @return int
     */
    public function assistantMessageCount($conversationId)
    {
        return $this->messageCountByRole($conversationId, 'assistant');
    }

    /**
     * How many customer messages this conversation has, ever.
     *
     * @param int $conversationId
     * @return int
     */
    public function userMessageCount($conversationId)
    {
        return $this->messageCountByRole($conversationId, 'user');
    }

    /**
     * @param int    $conversationId
     * @param string $role 'user' or 'assistant'
     * @param string $content
     * @return void
     */
    public function append($conversationId, $role, $content)
    {
        if (!in_array($role, ['user', 'assistant'], true)) {
            throw new InvalidArgumentException("Bad role: {$role}");
        }

        $this->pdo->prepare(
            'INSERT INTO messages (conversation_id, role, content) VALUES (?, ?, ?)'
        )->execute([(int) $conversationId, $role, $content]);

        $this->pdo->prepare(
            'UPDATE conversations SET last_message_at = NOW() WHERE id = ?'
        )->execute([(int) $conversationId]);
    }

    /**
     * Forget a conversation's history, keeping the conversation row.
     *
     * @param int $conversationId
     * @return void
     */
    public function clear($conversationId)
    {
        $this->pdo->prepare('DELETE FROM messages WHERE conversation_id = ?')
                  ->execute([(int) $conversationId]);

        $this->clearLastProductIds($conversationId);
        $this->clearSelectedProductId($conversationId);
    }

    /**
     * @param int $conversationId
     * @return int[]
     */
    public function lastProductIds($conversationId)
    {
        if (!$this->supportsLastProductIds()) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT last_product_ids FROM conversations WHERE id = ? LIMIT 1'
        );
        $stmt->execute([(int) $conversationId]);

        $raw = $stmt->fetchColumn();
        if ($raw === false || trim((string) $raw) === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $ids = [];
        foreach ($decoded as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return array_slice($ids, 0, 10);
    }

    /**
     * @param int   $conversationId
     * @param int[] $ids
     * @return void
     */
    public function setLastProductIds($conversationId, array $ids)
    {
        if (!$this->supportsLastProductIds()) {
            return;
        }

        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }
        $clean = array_slice($clean, 0, 10);

        $json = $clean !== [] ? json_encode($clean) : null;
        $this->pdo->prepare(
            'UPDATE conversations SET last_product_ids = ? WHERE id = ?'
        )->execute([$json, (int) $conversationId]);
    }

    /**
     * @param int $conversationId
     * @return void
     */
    public function clearLastProductIds($conversationId)
    {
        if (!$this->supportsLastProductIds()) {
            return;
        }

        $this->pdo->prepare(
            'UPDATE conversations SET last_product_ids = NULL WHERE id = ?'
        )->execute([(int) $conversationId]);
    }

    /**
     * @param int $conversationId
     * @return int|null
     */
    public function selectedProductId($conversationId)
    {
        if (!$this->supportsSelectedProductId()) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT selected_product_id FROM conversations WHERE id = ? LIMIT 1'
        );
        $stmt->execute([(int) $conversationId]);

        $id = $stmt->fetchColumn();
        $id = $id !== false ? (int) $id : 0;

        return $id > 0 ? $id : null;
    }

    /**
     * @param int $conversationId
     * @param int $productId
     * @return void
     */
    public function setSelectedProductId($conversationId, $productId)
    {
        if (!$this->supportsSelectedProductId()) {
            return;
        }

        $productId = (int) $productId;
        $this->pdo->prepare(
            'UPDATE conversations SET selected_product_id = ? WHERE id = ?'
        )->execute([$productId > 0 ? $productId : null, (int) $conversationId]);
    }

    /**
     * @param int $conversationId
     * @return void
     */
    public function clearSelectedProductId($conversationId)
    {
        if (!$this->supportsSelectedProductId()) {
            return;
        }

        $this->pdo->prepare(
            'UPDATE conversations SET selected_product_id = NULL WHERE id = ?'
        )->execute([(int) $conversationId]);
    }

    /**
     * Best-effort per-conversation metadata for debugging/admin filters.
     * Older cPanel deployments get the columns lazily when traffic arrives.
     *
     * @param int   $conversationId
     * @param array $metadata
     * @return void
     */
    public function setMetadata($conversationId, array $metadata)
    {
        $allowed = [
            'webshop' => "VARCHAR(32) NOT NULL DEFAULT ''",
            'client_ip' => "VARCHAR(64) NOT NULL DEFAULT ''",
            'customer_id' => "VARCHAR(191) NOT NULL DEFAULT ''",
            'customer_name' => "VARCHAR(191) NOT NULL DEFAULT ''",
            'wholesale_hint' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ];

        $sets = [];
        $params = [];
        foreach ($allowed as $column => $definition) {
            if (!array_key_exists($column, $metadata) || !$this->supportsMetadataColumn($column, $definition)) {
                continue;
            }

            $value = $metadata[$column];
            if ($column === 'wholesale_hint') {
                $value = !empty($value) ? 1 : 0;
            } else {
                $value = trim((string) $value);
                $max = $column === 'client_ip' ? 64 : ($column === 'webshop' ? 32 : 191);
                if (mb_strlen($value) > $max) {
                    $value = mb_substr($value, 0, $max);
                }
            }

            if ($value === '' && in_array($column, ['webshop', 'client_ip', 'customer_id', 'customer_name'], true)) {
                continue;
            }

            $sets[] = "`{$column}` = ?";
            $params[] = $value;
        }

        if ($sets === []) {
            return;
        }

        $params[] = (int) $conversationId;
        $this->pdo->prepare('UPDATE conversations SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    /**
     * @return bool
     */
    private function supportsLastProductIds()
    {
        if ($this->lastProductIdsSupported !== null) {
            return $this->lastProductIdsSupported;
        }

        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM conversations LIKE 'last_product_ids'");
            if ($stmt !== false && $stmt->fetch() !== false) {
                $this->lastProductIdsSupported = true;
                return true;
            }

            $this->pdo->exec('ALTER TABLE conversations ADD COLUMN last_product_ids TEXT NULL');
            $this->lastProductIdsSupported = true;
            return true;
        } catch (Exception $e) {
            error_log('ConversationStore: last_product_ids unavailable — ' . $e->getMessage());
            $this->lastProductIdsSupported = false;
            return false;
        }
    }

    /**
     * @return bool
     */
    private function supportsSelectedProductId()
    {
        if ($this->selectedProductIdSupported !== null) {
            return $this->selectedProductIdSupported;
        }

        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM conversations LIKE 'selected_product_id'");
            if ($stmt !== false && $stmt->fetch() !== false) {
                $this->selectedProductIdSupported = true;
                return true;
            }

            $this->pdo->exec('ALTER TABLE conversations ADD COLUMN selected_product_id INT UNSIGNED NULL');
            $this->selectedProductIdSupported = true;
            return true;
        } catch (Exception $e) {
            error_log('ConversationStore: selected_product_id unavailable — ' . $e->getMessage());
            $this->selectedProductIdSupported = false;
            return false;
        }
    }

    /**
     * @param string $column
     * @param string $definition
     * @return bool
     */
    private function supportsMetadataColumn($column, $definition)
    {
        if (isset($this->metadataColumns[$column])) {
            return $this->metadataColumns[$column];
        }

        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM conversations LIKE " . $this->pdo->quote($column));
            if ($stmt !== false && $stmt->fetch() !== false) {
                $this->metadataColumns[$column] = true;
                return true;
            }

            $this->pdo->exec("ALTER TABLE conversations ADD COLUMN `{$column}` {$definition}");
            if ($column === 'webshop') {
                $this->ensureConversationIndex('idx_webshop', 'webshop');
            } elseif ($column === 'client_ip') {
                $this->ensureConversationIndex('idx_client_ip', 'client_ip');
            }
            $this->metadataColumns[$column] = true;
            return true;
        } catch (Exception $e) {
            error_log('ConversationStore: metadata column unavailable — ' . $column . ' — ' . $e->getMessage());
            $this->metadataColumns[$column] = false;
            return false;
        }
    }

    /**
     * @param string $index
     * @param string $column
     * @return void
     */
    private function ensureConversationIndex($index, $column)
    {
        try {
            $stmt = $this->pdo->query("SHOW INDEX FROM conversations WHERE Key_name = " . $this->pdo->quote($index));
            if ($stmt !== false && $stmt->fetch() !== false) {
                return;
            }
            $this->pdo->exec("ALTER TABLE conversations ADD KEY `{$index}` (`{$column}`)");
        } catch (Exception $e) {
            error_log('ConversationStore: metadata index unavailable — ' . $index . ' — ' . $e->getMessage());
        }
    }
}
