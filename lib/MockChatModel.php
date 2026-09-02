<?php
require_once __DIR__ . '/ChatModel.php';
require_once __DIR__ . '/ScopeGuard.php';

/**
 * Offline stand-in for the AI provider, so the whole system can be exercised without
 * spending anything on the API.
 *
 * It genuinely runs the product-search tool against the real catalog, so this
 * does test the parts most likely to be broken: the widget, sessions,
 * conversation history, the tool plumbing, search quality, rate limiting and
 * error handling.
 *
 * What it does NOT test is language quality — understanding of Bosnian, tone,
 * when to escalate, refusing to invent facts. Those are the model's job and
 * only the real API can show them. Treat a green run here as "the machinery
 * works", not "the bot is good".
 *
 * Replies are prefixed so nobody can mistake mock output for the real thing.
 *
 * Target: PHP 7.4.
 */
class MockChatModel implements ChatModel
{
    /** @var string */
    private $prefix;

    /** @param string $prefix */
    public function __construct($prefix = '[MOCK] ')
    {
        $this->prefix = $prefix;
    }

    /**
     * @param array    $messages
     * @param string   $systemPrompt
     * @param array    $tools
     * @param callable $executor
     * @param int      $maxTokens
     * @param int      $maxRounds
     * @param bool     $forceToolUse Unused - the mock always answers
     *                       deterministically without a real model deciding
     *                       whether to skip a tool.
     * @return string
     */
    public function chatWithTools(array $messages, $systemPrompt, array $tools, $executor, $maxTokens = 1024, $maxRounds = 4, $forceToolUse = false)
    {
        $question = $this->lastUserText($messages);
        $norm     = Text::normalize($question);

        $faq = $this->faqAnswer($norm);
        if ($faq !== null) {
            return $this->prefix . $faq;
        }

        $offTopic = $this->offTopicAnswer($norm);
        if ($offTopic !== null) {
            return $this->prefix . $offTopic;
        }

        // Not an FAQ topic - treat it as a product question and really call the
        // tool, exactly as the real model would.
        $toolInput = $this->searchInput($messages, $question);
        $raw = call_user_func($executor, 'search_products', $toolInput);
        $isActionSearch = !empty($toolInput['action_only']);
        $topic = $this->productTopicLabel(isset($toolInput['query']) ? (string) $toolInput['query'] : $question);

        $results = json_decode((string) $raw, true);

        // Did the customer name a brand we carry? If the results are not from
        // that brand, say so and offer what we do have from it — the same shape
        // of answer the real model is instructed to give.
        $brandNote = $this->brandFallback($norm, $results, $executor);
        if ($brandNote !== null) {
            return $this->prefix . $brandNote;
        }

        if (!is_array($results) || $results === []) {
            return $this->prefix . 'Nažalost, nisam pronašao taj proizvod u katalogu. '
                 . 'Nazovite nas na ' . (string) config_get('support_phone', '0800 22 432') . ' i provjerit ćemo za vas.';
        }

        $lines = [];
        foreach (array_slice($results, 0, 3) as $p) {
            $price = isset($p['price']) && $p['price'] !== null
                ? $this->formatKm((float) $p['price'])
                : 'cijena na upit';
            $stock = !empty($p['in_stock']) ? 'na stanju' : 'trenutno nije na stanju';
            $action = '';
            if (!empty($p['is_action'])) {
                $parts = ['akcija'];
                if (isset($p['price_before']) && $p['price_before'] !== null) {
                    $parts[] = 'bilo ' . $this->formatKm((float) $p['price_before']);
                }
                if (isset($p['discount_percent']) && $p['discount_percent'] !== null) {
                    $parts[] = '-' . $this->formatPercent((float) $p['discount_percent']);
                }
                $action = ' — ' . implode(', ', $parts);
            }
            $lines[] = '• ' . $p['name'] . ' — ' . $price . ' (' . $stock . ')' . $action;
        }

        if ($isActionSearch) {
            $intro = $topic !== ''
                ? 'Evo nekoliko akcijskih ponuda za ' . $topic . ' koje bi vas mogle zanimati:'
                : 'Evo nekoliko akcijskih ponuda iz našeg asortimana koje bi vas mogle zanimati:';
            $outro = $this->friendlyProductClosing(true);

            return $this->prefix . $intro . "\n" . implode("\n", $lines) . "\n\n" . $outro;
        }

        return $this->prefix . "Evo šta imamo:\n" . implode("\n", $lines)
             . "\n\n" . $this->friendlyProductClosing(false);
    }

    /**
     * Canned answers for the common non-product questions.
     *
     * @param string $norm Normalised question text.
     * @return string|null Null when no topic matches.
     */
    private function faqAnswer($norm)
    {
        if ($this->looksLikePhoneProductQuery($norm) || $this->looksLikeCableProductQuery($norm)) {
            return null;
        }

        // "kontakt sprej" (contact cleaner) is a product, not a request for
        // our contact details.
        if (preg_match('/\bkontakt\w*\b/u', $norm) && preg_match('/\b(?:sprej\w*|cistac\w*|čistač\w*)\b/u', $norm)) {
            return null;
        }

        $supportPhone   = (string) config_get('support_phone', '0800 22 432');
        $supportMobile  = (string) config_get('support_mobile', '');
        $supportEmail   = (string) config_get('support_email', 'info@digitalis.ba');
        $servicePhone   = (string) config_get('service_phone', '062 989 770');
        $deliveryCost   = (string) config_get('delivery_cost', '');
        $installmentUrl = (string) config_get('installment_url', '');
        $storeName      = (string) config_get('store_name', 'D-Store');
        $pensionFinancingAvailable = config_get('pension_financing_available', true);

        $topics = [
            'dostava|isporuk|postarin|slanje|posalj'
                => $deliveryCost !== ''
                    ? "Dostavljamo brzom poštom. Trošak dostave je {$deliveryCost}. "
                        . "Za tačan rok isporuke nazovite {$supportPhone}."
                    : "Za trošak i rok isporuke nazovite {$supportPhone} ili pišite na {$supportEmail}.",

            // The PIO/MIO pension-fund credit line is a Bosnia-specific
            // government program - there is no equivalent to swap in for a
            // deployment in another country, so this whole answer only
            // makes sense while this bot is speaking for a Bosnian store
            // that actually runs it (pension_financing_available).
            'penzion\w*|umirovljen\w*|\bpio\b|\bmio\b|pio\s*\/\s*mio|pio\s+rs'
                => $pensionFinancingAvailable
                    ? "Za penzionere/umirovljenike smo omogućili jednostavnu kupovinu na rate, uz minimalnu proceduru:\n"
                        . "- Kreditna linija preko fonda PIO/MIO za penzionere/umirovljenike sa 0% kamate\n"
                        . "- Kreditna linija preko fonda PIO RS sa 0% kamate\n\n"
                        . "Potrebno je slikati i poslati na broj " . ($supportMobile !== '' ? $supportMobile : $supportPhone) . ":\n"
                        . "- Ličnu/osobnu kartu (obostrano)\n"
                        . "- Posljednji ček od penzije/mirovine\n"
                        . "- Sliku željenog artikla/artikala\n\n"
                        . "Dostava je unutar BiH na adresu. Trošak usluge obrade kredita iznosi 10% od ukupne cijene artikla."
                    : null,

            'rate|ratama|rata|obroc\w*|obrocn\w*|kreditn|na\s+rate'
                => $installmentUrl !== ''
                    ? "Za kupovinu na rate pogledajte ovu {$storeName} stranicu: {$installmentUrl}"
                    : null,

            'placan|platit|pouzec|uplat|karticom|debitn|visa|mastercard'
                => "Možete platiti pouzećem kuriru prilikom preuzimanja, ili bankovnim transferom."
                    . ($installmentUrl !== '' ? " Za kupovinu na rate pogledajte: {$installmentUrl}" : ''),

            'garanci'
                => "Garancija zavisi od proizvoda. Servis rješava garancijske zahtjeve "
                 . "u roku od najviše 45 dana. Kontakt servisa: {$servicePhone}.",

            'povrat|reklamacij|vratit|zamjen'
                => "Povrate rješavamo od slučaja do slučaja. Javite se na {$supportPhone} "
                 . "ili {$supportEmail} i objasnit ćemo proceduru.",

            'kontakt|telefon|broj|mejl|email|mail'
                => "Možete nas dobiti na broj {$supportPhone}"
                    . ($supportMobile !== '' ? ", mobitel {$supportMobile}" : '')
                    . ", ili e-mailom na {$supportEmail}.",

            'radno vrijeme|radite|otvoreni|kada ste'
                => "Za radno vrijeme poslovnica nazovite {$supportPhone}.",
        ];

        foreach ($topics as $pattern => $answer) {
            if ($answer !== null && preg_match('/' . $pattern . '/u', $norm)) {
                return $answer;
            }
        }

        return null;
    }

    /**
     * "Koji vam je telefon?" is a contact question, but "Samsung telefon" is a
     * product search. The mock has no language-model judgement, so keep this
     * small intent guard here for local testing.
     *
     * @param string $norm Normalised question text.
     * @return bool
     */
    private function looksLikePhoneProductQuery($norm)
    {
        if (!preg_match('/\b(?:telefon\w*|mobitel\w*|smartphone\w*)\b/u', $norm)) {
            return false;
        }

        if (preg_match('/\b(?:kontakt|broj|nazvat|nazvati|zvati|pozvati|mejl|email|mail|info|viber|whatsapp)\b/u', $norm)) {
            return false;
        }

        if (preg_match('/\b(?:samsung|iphone|apple|xiaomi|huawei|honor|motorola|nokia|mobitel\w*|smartphone\w*|obicn\w*|tipk\w*|senior|stoln\w*|fiksn\w*|dect|bezicn\w*)\b/u', $norm)) {
            return true;
        }

        return preg_match('/\b(?:imate|imas|pokazi|prikazi|daj|trazim|trebam|kupit|kupiti|cijena|cijenu|kosta|kostaju)\b/u', $norm) === 1;
    }

    /**
     * Prevent local mock FAQ answers from stealing product requests such as
     * "telefonski kablovi" just because they contain the word "telefon".
     *
     * @param string $norm Normalised question text.
     * @return bool
     */
    private function looksLikeCableProductQuery($norm)
    {
        if (preg_match('/\b(?:kabl\w*|kabel\w*)\b/u', $norm)) {
            return true;
        }

        return preg_match(
            '/\b(?:hdmi|scart|s\s*vhs|svhs|display\s*port|displayport|vga|dvi|toslink|koaksijaln\w*|coax\w*|rg\s*6|rg6|mrezn\w*|mrežn\w*|patch|lan|ethernet|rj45|rj11|aux|rca|cinch|cinc)\b/u',
            $norm
        ) === 1;
    }

    /**
     * Canned answer for small talk and clearly non-store questions.
     *
     * The real model follows the system prompt. The mock has no judgement, so
     * it needs a practical list of common non-store intents; otherwise "kako
     * si" becomes a product search and the local demo looks broken.
     *
     * @param string $norm Normalised question text.
     * @return string|null Null when this may be a store/product question.
     */
    private function offTopicAnswer($norm)
    {
        return ScopeGuard::answer($norm);
    }

    /**
     * @param float $value
     * @return string
     */
    private function formatKm($value)
    {
        $currency = (string) config_get('currency', 'KM');

        return number_format((float) $value, 2, ',', '.') . ' ' . $currency;
    }

    /**
     * @param float $value
     * @return string
     */
    private function formatPercent($value)
    {
        // Rounded to a whole number - the real webshop shows "-6%", not
        // "-6,25%"; discount_percent is a precise computed value
        // ((before-after)/before*100), not a number a human ever typed.
        return (string) (int) round((float) $value) . '%';
    }

    /**
     * Build a short customer-facing label for action answers.
     *
     * @param string $query
     * @return string
     */
    private function productTopicLabel($query)
    {
        $budget = Text::extractBudget($query);
        $sort   = Text::extractSortIntent($budget['query']);
        $clean  = $this->stripActionWords($sort['query']);
        $tokens = Text::meaningfulTokens($clean);

        if ($tokens === []) {
            return '';
        }

        $label = implode(' ', $tokens);
        $nice = [
            'ves masine' => 'veš mašine',
            'ves masina' => 'veš mašine',
            'masine ves' => 'veš mašine',
            'masina ves' => 'veš mašine',
            'usisavac' => 'usisivače',
            'usisavaci' => 'usisivače',
            'televizori' => 'televizore',
            'televizor' => 'televizore',
        ];

        return isset($nice[$label]) ? $nice[$label] : $label;
    }

    /**
     * @param bool $hasAction
     * @return string
     */
    private function friendlyProductClosing($hasAction)
    {
        if ($hasAction) {
            return 'Preporučujem da pogledate detalje, opis i upute na stranici proizvoda. '
                . 'Da li vas zanima još neka akcijska cijena ili detalji za neki od ovih artikala?';
        }

        return 'Preporučujem da pogledate detalje, opis i upute na stranici proizvoda. '
            . 'Da li vas nešto konkretno zanima?';
    }

    /**
     * Build the product-search input for the mock.
     *
     * Real OpenAI sees the conversation history and can understand that
     * "imate li neke do 600 KM" or "a koje imate iznad 1000 KM" after "ves
     * masine" still means washing machines. The mock has no reasoning, so we
     * carry that context here.
     *
     * @param array  $messages
     * @param string $question
     * @return array
     */
    private function searchInput(array $messages, $question)
    {
        $input  = ['query' => $question, 'limit' => 3];
        $budget = Text::extractBudget($question);
        $sort   = Text::extractSortIntent($budget['query']);
        $actionOnly = $this->looksLikeActionRequest($question);

        $tokens = Text::meaningfulTokens($this->stripActionWords($sort['query']));
        if ($tokens === []) {
            $previous = $this->previousProductQuestion($messages);
            if ($previous !== null) {
                $input['query'] = $previous;
            }
        }

        if ($budget['min_price'] !== null) {
            $input['min_price'] = $budget['min_price'];
        }
        if ($budget['max_price'] !== null) {
            $input['max_price'] = $budget['max_price'];
        }
        if (isset($budget['target_price']) && $budget['target_price'] !== null) {
            $input['target_price'] = $budget['target_price'];
        }
        if ($sort['sort'] !== null) {
            $input['sort'] = $sort['sort'];
        }
        if ($actionOnly || $sort['sort'] === 'discount_desc') {
            $input['action_only'] = true;
        }

        return $input;
    }

    /**
     * @param string $message
     * @return bool
     */
    private function looksLikeActionRequest($message)
    {
        $norm = Text::normalize($message);

        return preg_match(
            '/\b(?:akcij\w*|popust\w*|snizen\w*|sniz\w*|rasprodaj\w*|promo\w*|promocij\w*)\b/u',
            $norm
        ) === 1;
    }

    /**
     * @param string $message
     * @return string
     */
    private function stripActionWords($message)
    {
        $message = preg_replace(
            '/\b(?:akcij\w*|popust\w*|sni(?:z|ž)en\w*|sniz\w*|rasprodaj\w*|promo\w*|promocij\w*)\b/iu',
            ' ',
            (string) $message
        );

        return trim(preg_replace('/\s+/u', ' ', $message));
    }

    /**
     * Find the previous user message that looks like a product search.
     *
     * @param array $messages
     * @return string|null
     */
    private function previousProductQuestion(array $messages)
    {
        // Skip the current message at the end.
        for ($i = count($messages) - 2; $i >= 0; $i--) {
            if (!isset($messages[$i]['role'], $messages[$i]['content'])
                || $messages[$i]['role'] !== 'user'
                || !is_string($messages[$i]['content'])
            ) {
                continue;
            }

            $candidate = trim($messages[$i]['content']);
            if ($candidate === '') {
                continue;
            }
            if (ScopeGuard::answer($candidate) !== null) {
                continue;
            }

            $candidateBudget = Text::extractBudget($candidate);
            $candidateSort = Text::extractSortIntent($candidateBudget['query']);
            $tokens = Text::meaningfulTokens($this->stripActionWords($candidateSort['query']));
            if ($tokens !== []) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * If a brand was asked for but the results are not that brand, build the
     * "we do not have that, but we have these" answer.
     *
     * @param string   $norm     Normalised question.
     * @param mixed    $results  Decoded search results.
     * @param callable $executor
     * @return string|null Null when this does not apply.
     */
    private function brandFallback($norm, $results, $executor)
    {
        // A short list is enough for the mock; the real model recognises any brand.
        $known = ['samsung', 'xiaomi', 'philips', 'bosch', 'lg', 'sony', 'hisense', 'gorenje'];

        $asked = null;
        foreach ($known as $brand) {
            if (strpos($norm, $brand) !== false) {
                $asked = $brand;
                break;
            }
        }
        if ($asked === null) {
            return null;
        }

        // Does any result actually belong to that brand?
        if (is_array($results)) {
            foreach ($results as $p) {
                if (isset($p['brand']) && stripos($p['brand'], $asked) !== false) {
                    return null;   // results really are that brand — nothing to correct
                }
            }
        }

        $raw  = call_user_func($executor, 'brand_categories', ['brand' => $asked]);
        $info = json_decode((string) $raw, true);

        if (!is_array($info) || empty($info['categories'])) {
            return null;
        }

        $names = [];
        foreach ($info['categories'] as $c) {
            $names[] = mb_strtolower($c['category']);
        }

        return 'Nemamo taj proizvod od ' . $info['brand'] . ' na stanju. '
             . 'Od ' . $info['brand'] . ' imamo: ' . implode(', ', $names)
             . '. Mogu li vam nešto od toga pokazati?';
    }

    /**
     * @param array $messages
     * @return string
     */
    private function lastUserText(array $messages)
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (isset($messages[$i]['role'], $messages[$i]['content'])
                && $messages[$i]['role'] === 'user'
                && is_string($messages[$i]['content'])
            ) {
                return $messages[$i]['content'];
            }
        }
        return '';
    }
}
