-- dstore chatbot — catalog schema
--
-- Mirrors digitalis.ba/api into local MySQL so product search is a fast indexed
-- query instead of an 11.7 MB HTTP download per customer message.
--
-- Column names follow OUR conventions, not the API's inconsistent ones
-- (the API mixes `ID`, `EAN`, `Model`, `name`); the mapping lives in
-- tools/sync_catalog.php.
--
-- Run once:
--   C:\xampp\mysql\bin\mysql.exe -u root < db\schema.sql

CREATE DATABASE IF NOT EXISTS dstore_chat
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE dstore_chat;

-- --------------------------------------------------------------------------
-- Reference tables
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS brands (
    id   INT UNSIGNED NOT NULL PRIMARY KEY,
    name VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supercategories (
    id   INT UNSIGNED NOT NULL PRIMARY KEY,
    name VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id                INT UNSIGNED NOT NULL PRIMARY KEY,
    super_category_id INT UNSIGNED NULL,
    name              VARCHAR(255) NOT NULL DEFAULT '',
    KEY idx_super (super_category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subcategories (
    id          INT UNSIGNED NOT NULL PRIMARY KEY,
    category_id INT UNSIGNED NULL,
    name        VARCHAR(255) NOT NULL DEFAULT '',
    KEY idx_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Products
--
-- `search_text` holds a lowercased, diacritic-stripped copy of the searchable
-- fields. Bosnian customers routinely type "prijemnik satelitski" without
-- diacritics, and "Grijalica" / "grijalica" / "GRIJALICA" must all match.
-- Normalising once at sync time means the query side stays simple and fast.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS products (
    id              INT UNSIGNED NOT NULL PRIMARY KEY,
    ean             VARCHAR(64)   NOT NULL DEFAULT '',
    model           VARCHAR(255)  NOT NULL DEFAULT '',
    name            VARCHAR(512)  NOT NULL DEFAULT '',
    description     TEXT          NULL,
    image_url       VARCHAR(1024) NULL,

    brand_id        INT UNSIGNED  NULL,
    category_id     INT UNSIGNED  NULL,
    subcategory_id  INT UNSIGNED  NULL,

    price           DECIMAL(10,2) NULL,
    is_action       TINYINT(1)     NOT NULL DEFAULT 0,
    action_price    DECIMAL(10,2) NULL,
    price_before    DECIMAL(10,2) NULL,
    discount_percent DECIMAL(5,2) NULL,
    action_start    VARCHAR(32)   NULL,
    action_end      VARCHAR(32)   NULL,
    stock           DECIMAL(12,2) NOT NULL DEFAULT 0,
    warranty_months SMALLINT UNSIGNED NULL,
    weight_kg       DECIMAL(10,3) NULL,

    -- Per-storefront visibility. is_vp = shown on the wholesale site
    -- (digitalis.ba), is_mp = shown on the retail site (dstore.ba). Default
    -- to visible so a feed row that omits the field is never hidden by
    -- accident.
    is_vp           TINYINT(1)    NOT NULL DEFAULT 1,
    is_mp           TINYINT(1)    NOT NULL DEFAULT 1,

    -- Real "Novo" badge flag from the webshop. Default 0 - unlike
    -- is_vp/is_mp, hiding is the safe default when a feed row omits it.
    new_product     TINYINT(1)    NOT NULL DEFAULT 0,

    search_text     TEXT          NULL,

    -- Name + model + brand only. A match here means the product IS the thing
    -- being searched for, whereas a match in the description often means only
    -- that it is an accessory FOR that thing ("ruksak za laptop"). Ranking
    -- weights this far above search_text.
    name_text       TEXT          NULL,

    -- First word of the product name, indexed. Identifying the product type in
    -- a query means asking "how many names START with this word", which on a
    -- TEXT column is a full scan; on this it is an index lookup.
    head_word       VARCHAR(64)   NULL,

    -- Set explicitly by the sync to the run's start time. Rows still carrying
    -- an older timestamp after a run are products the feed no longer lists,
    -- i.e. discontinued, and get deleted. Deliberately NOT `ON UPDATE
    -- CURRENT_TIMESTAMP`: MySQL skips the update when every value is
    -- unchanged, which would make unchanged rows look stale.
    synced_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_brand       (brand_id),
    KEY idx_category    (category_id),
    KEY idx_subcategory (subcategory_id),
    KEY idx_stock       (stock),
    KEY idx_action      (is_action),
    KEY idx_ean         (ean),
    KEY idx_head_word   (head_word),
    FULLTEXT KEY ft_search (search_text),
    FULLTEXT KEY ft_name   (name_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Conversations
--
-- Make.com holds no cookies, so PHP sessions are useless here. History is
-- keyed by an explicit conversation id: the Viber user id, the WhatsApp phone
-- number, or the Messenger PSID, namespaced by channel.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS conversations (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    channel         VARCHAR(32)  NOT NULL,
    external_id     VARCHAR(191) NOT NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_message_at TIMESTAMP    NULL,
    last_product_ids TEXT         NULL,
    selected_product_id INT UNSIGNED NULL,
    webshop         VARCHAR(32)  NOT NULL DEFAULT '',
    client_ip       VARCHAR(64)  NOT NULL DEFAULT '',
    customer_id     VARCHAR(191) NOT NULL DEFAULT '',
    customer_name   VARCHAR(191) NOT NULL DEFAULT '',
    wholesale_hint  TINYINT(1)   NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_channel_user (channel, external_id),
    KEY idx_last (last_message_at),
    KEY idx_webshop (webshop),
    KEY idx_client_ip (client_ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    role            ENUM('user','assistant') NOT NULL,
    content         MEDIUMTEXT NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_conversation (conversation_id, id),
    CONSTRAINT fk_messages_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_turn_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NULL,
    channel         VARCHAR(32)  NOT NULL DEFAULT '',
    external_id     VARCHAR(191) NOT NULL DEFAULT '',
    webshop         VARCHAR(32)  NOT NULL DEFAULT '',
    client_ip       VARCHAR(64)  NOT NULL DEFAULT '',
    customer_id     VARCHAR(191) NOT NULL DEFAULT '',
    customer_name   VARCHAR(191) NOT NULL DEFAULT '',
    wholesale_hint  TINYINT(1)   NOT NULL DEFAULT 0,
    path            VARCHAR(32)  NOT NULL DEFAULT '',
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
    KEY idx_client_ip (client_ip),
    CONSTRAINT fk_turn_logs_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversation_feedback (
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
    KEY idx_rating (rating),
    CONSTRAINT fk_feedback_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Sync bookkeeping — so you can answer "when did the catalog last update?"
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sync_runs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    started_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at   TIMESTAMP NULL,
    status        ENUM('running','ok','failed') NOT NULL DEFAULT 'running',
    products_seen INT UNSIGNED NOT NULL DEFAULT 0,
    note          TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
