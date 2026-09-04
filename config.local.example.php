<?php
/**
 * Copy this file to `config.local.php` and fill in the real values.
 *
 * config.local.php holds secrets and is git-ignored. Never commit it,
 * and never let any of these values reach the browser.
 */

return [
    // --- Digitalis (dstore) product API -------------------------------------
    // Use production on cPanel. For local experiments you can temporarily
    // switch this to https://test.digitalis.ba/api.
    'digitalis_base_url' => 'https://digitalis.ba/api',
    'digitalis_token'    => 'PASTE_YOUR_DIGITALIS_TOKEN_HERE',

    // --- Shared secret for endpoint/search.php -----------------------------------
    // Make.com sends this as `Authorization: Bearer <token>`. Invent a long
    // random string. This endpoint exposes prices and stock, so it must not be
    // left open. Generate one with:
    //   C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(24));"
    'search_api_token' => '',

    // --- OpenAI --------------------------------------------------------------
    // NOTE: a ChatGPT Plus subscription does NOT include API access — the key
    // comes from platform.openai.com, which bills separately.
    'openai_key'   => '',
    'openai_model' => 'gpt-5.6-luna',
    // Newer OpenAI models reject 'max_tokens' and want
    // 'max_completion_tokens'. Set true if the API complains about it.
    'openai_max_completion_tokens' => false,
    // 0-2, lower = more deterministic/repeatable, higher = more varied.
    // Leave commented (null) to use OpenAI's own default (1.0).
    'openai_temperature' => 0.4,

    // --- Offline testing ----------------------------------------------------
    // true = fake the language model but still run the real product search, so
    // you can test the widget, history and catalog without spending anything.
    // Replies are prefixed [MOCK]. Set false once you have the OpenAI key.
    'use_mock_ai' => false,

    // --- Website widget -----------------------------------------------------
    // Sites allowed to use the widget. The browser cannot carry the API token,
    // so this stops other sites embedding the widget and spending your credit.
    // Leave empty to allow any origin (fine locally, set it in production).
    'allowed_origins' => [
        // 'https://digitalis.ba',
        // 'https://www.digitalis.ba',
    ],

    // --- Working hours ------------------------------------------------------
    // Outside these hours the bot says staff will reply in the morning instead
    // of implying someone is available. Set start === end for always-open.
    'timezone'             => 'Europe/Sarajevo',
    'business_hours_start' => 8,
    'business_hours_end'   => 16,
    'working_days'         => [1, 2, 3, 4, 5],   // 1 = Monday … 7 = Sunday

    // --- Optional: chatbot tuning -------------------------------------------
    // All of these have sensible defaults; uncomment only to override.
    // 'max_reply_tokens'   => 1024,   // replies are short by design
    // 'max_message_length' => 1500,   // longest customer message accepted
    // Conversation history is not cost-limited by total bot messages anymore;
    // cost protection below counts only real OpenAI calls.
    // 'product_card_limit' => 8,      // cards returned for a product slider
    // 'rate_limit_max'     => 20,     // messages allowed per window
    // 'rate_limit_window'  => 300,    // window length in seconds
    // 'burst_rate_limit_max' => 6,       // fast-message abuse guard
    // 'burst_rate_limit_window' => 60,   // seconds
    // 'count_all_message_daily_limits' => false, // true also caps cheap/local replies
    // 'visitor_daily_limit' => 80,       // per browser visitor id, only used when above is true
    // 'visitor_daily_window' => 86400,
    // 'ip_daily_limit'      => 120,      // per IP address, only used when above is true
    // 'ip_daily_window'     => 86400,
    // 'global_daily_limit'  => 2000,     // all-message safety valve, only used when above is true
    // 'global_daily_window' => 86400,
    // 'ai_user_daily_limit' => 30,       // only real OpenAI calls
    // 'ai_user_daily_window' => 86400,
    // 'ai_global_daily_limit' => 500,    // total real OpenAI calls/day
    // 'ai_global_daily_window' => 86400,
    // 'max_message_urls' => 2,           // obvious spam: too many links
    // 'max_message_newlines' => 20,      // pasted bulk text guard
    // 'max_repeated_character_run' => 80,
    // 'max_repeated_word_run' => 35,
    // 'canned_replies_file' => __DIR__ . '/data/canned_replies.json',
    // 'feedback_rate_limit_max' => 10,   // feedback submissions per hour/IP
    // 'feedback_rate_limit_window' => 3600,
    // 'trusted_proxies'    => [],     // your reverse proxy IPs, if any
    // 'debug_errors'       => false,  // true = show real errors in responses.
    //                                 // NEVER true in production.
    // Admin dashboard: /chatbot/admin/conversations.php?token=...
    // Generate a long random value and keep it private.
    // 'admin_token' => '',
    // Optional: allow dashboard from trusted office/test IPs without token.
    // 'admin_allowed_ips' => ['77.78.207.53'],
    // Let a trusted tester IP run deeper tests while public visitors keep the
    // normal limits above.
    // 'ip_rate_limit_overrides' => [
    //     '77.78.207.53' => [
    //         'burst_rate_limit_max' => 200,
    //         'burst_rate_limit_window' => 60,
    //         'rate_limit_max' => 500,
    //         'rate_limit_window' => 300,
    //         'visitor_daily_limit' => 2000,
    //         'ip_daily_limit' => 3000,
    //         'ai_user_daily_limit' => 200,
    //         'ai_global_daily_limit' => 1000,
    //     ],
    // ],
    // 'shop_base_url'      => 'https://www.digitalis.ba',
    // 'image_base_url'     => 'https://www.digitalis.ba',
    // 'brand_image_base_url' => 'https://www.digitalis.ba',
    // Deploy this codebase once per storefront and set shop_base_url to that
    // store's own domain. Product page URL shape for that store:
    //   'webshop' (default) -> {shop_base_url}/webshop/proizvod/{id}/{seo}
    //                          (digitalis.ba, zed.hr, optibox.rs)
    //   'flat'              -> {shop_base_url}/{brand}/{seo}-{id}   (dstore.ba)
    // 'shop_url_style'     => 'webshop',
    // If one falcom.ba backend serves several storefronts, browser Origin can
    // override only storefront-safe display/link settings for product cards.
    // 'storefront_origin_overrides' => [
    //     'https://www.dstore.ba' => [
    //         'shop_base_url' => 'https://www.dstore.ba',
    //         'image_base_url' => 'https://www.digitalis.ba',
    //         'brand_image_base_url' => 'https://www.digitalis.ba',
    //         'shop_url_style' => 'flat',
    //         'catalog_visibility_column' => 'is_mp',
    //         'catalog_wholesale_column' => '',
    //         'store_name' => 'D-Store',
    //         'assistant_name' => 'Dstore AI',
    //         'installment_url' => 'https://www.dstore.ba/kupovina-na-rate',
    //     ],
    //     'https://www.digitalis.ba' => [
    //         'shop_base_url' => 'https://www.digitalis.ba',
    //         'image_base_url' => 'https://www.digitalis.ba',
    //         'brand_image_base_url' => 'https://www.digitalis.ba',
    //         'shop_url_style' => 'webshop',
    //         'catalog_visibility_column' => 'is_mp',
    //         'catalog_wholesale_column' => 'is_vp',
    //         'faq_file' => 'faq.digitalis.txt',
    //         'store_name' => 'Digitalis',
    //         'assistant_name' => 'Digitalis AI',
    //         'installment_url' => '',
    //     ],
    // ],
    // Same idea, but selected explicitly by embed.js via data-webshop="dstore".
    // 'storefront_webshop_overrides' => [
    //     'dstore' => [
    //         'shop_base_url' => 'https://www.dstore.ba',
    //         'image_base_url' => 'https://www.digitalis.ba',
    //         'brand_image_base_url' => 'https://www.digitalis.ba',
    //         'shop_url_style' => 'flat',
    //         'catalog_visibility_column' => 'is_mp',
    //         'catalog_wholesale_column' => '',
    //         'store_name' => 'D-Store',
    //         'assistant_name' => 'Dstore AI',
    //         'installment_url' => 'https://www.dstore.ba/kupovina-na-rate',
    //     ],
    //     'digitalis' => [
    //         'shop_base_url' => 'https://www.digitalis.ba',
    //         'image_base_url' => 'https://www.digitalis.ba',
    //         'brand_image_base_url' => 'https://www.digitalis.ba',
    //         'shop_url_style' => 'webshop',
    //         'catalog_visibility_column' => 'is_mp',
    //         'catalog_wholesale_column' => 'is_vp',
    //         'faq_file' => 'faq.digitalis.txt',
    //         'store_name' => 'Digitalis',
    //         'assistant_name' => 'Digitalis AI',
    //         'installment_url' => '',
    //     ],
    // ],
    // Base public visibility is the retail/public catalog. On wholesale
    // storefronts, verified logged-in customers can additionally see is_vp.
    'catalog_visibility_column' => 'is_mp',
    // 'catalog_wholesale_column' => 'is_vp',
    // Currency this store's prices are already in (display only, no
    // conversion happens): 'KM' (default), 'EUR', 'RSD', ...
    // 'currency'           => 'KM',
    // Contact facts the deterministic/mock reply layer quotes directly
    // (the real AI reads prompts/faq.txt instead - give each deployment its
    // own copy of that file with its own facts, no code change needed there).
    // 'support_phone'      => '0800 22 432',
    // 'support_mobile'     => '061 095 095',
    // 'support_email'      => 'info@digitalis.ba',
    // 'service_phone'      => '062 989 770',
    // 'delivery_cost'      => '10 KM',
    // 'installment_url'    => 'https://www.dstore.ba/kupovina-na-rate',
    // 'enrich_action_prices' => true, // read public product pages during sync
    //                                  // to fill action price and discount %.
    // Brand identity for the same deterministic/mock reply layer.
    // 'store_name'         => 'D-Store',
    // 'assistant_name'     => 'Dstore AI',
    // 'company_name'       => 'Digitalis',
    // The PIO/MIO pension-fund credit line is Bosnia-specific. Set false for
    // any deployment outside Bosnia so the bot never promises a program the
    // store does not actually run.
    // 'pension_financing_available' => true,

    // --- Local MySQL (XAMPP defaults) ---------------------------------------
    'db_host' => '127.0.0.1',
    'db_name' => 'dstore_chat',
    'db_user' => 'root',
    'db_pass' => '',
];
