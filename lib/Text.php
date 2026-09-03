<?php
/**
 * Text normalisation for Bosnian/Croatian/Serbian product search.
 *
 * Customers type "prijemnik satelitski" without diacritics, "GRIJALICA" in
 * caps, and "djak" for "đak". Normalising both the indexed text and the query
 * the same way makes all of those match without any query-side cleverness.
 *
 * Target: PHP 7.4.
 */
class Text
{
    /**
     * Diacritic map. Covers BCS plus the Western European letters that turn up
     * in brand names (Müller, Café, Škoda).
     *
     * @var array<string,string>
     */
    private static $diacritics = [
        'č' => 'c', 'ć' => 'c', 'ç' => 'c',
        'š' => 's', 'ś' => 's', 'ş' => 's',
        'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
        'đ' => 'd', 'ď' => 'd',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ů' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'ñ' => 'n', 'ň' => 'n', 'ń' => 'n',
        'ř' => 'r', 'ť' => 't', 'ł' => 'l',
        'ß' => 'ss',
    ];

    /**
     * Lowercase, strip diacritics, and flatten whitespace.
     *
     * Apply this to indexed text AND to the query — the two only match because
     * they go through the identical transformation.
     *
     * @param string $text
     * @return string
     */
    public static function normalize($text)
    {
        $text = mb_strtolower((string) $text, 'UTF-8');
        $text = strtr($text, self::$diacritics);

        // "dj" is the usual keyboard substitute for "đ", which the map above
        // has already turned into "d". Collapsing "dj" to "d" too makes both
        // spellings land in the same place. It slightly mangles unrelated words
        // ("odjava" -> "odava"), which is harmless as long as the query is
        // normalised identically.
        $text = str_replace('dj', 'd', $text);

        // Ekavica/ijekavica: the catalog text (grijalica, grijanje, grijač...)
        // is consistently ijekavica even on optibox.rs, but a Serbian
        // (ekavica) customer types "grejalica" - zero letters in common with
        // "grij", so plain fulltext matching finds nothing even though the
        // product is right there. Collapsing this one root fixes the whole
        // heater/heating product line at once, same tradeoff as "dj" above.
        // Not a general ekavica normalizer - only add another root here once
        // it is actually seen failing, to avoid guessing at collisions.
        $text = str_replace('grej', 'grij', $text);

        // "tašna" is a common everyday word for "bag" (colloquial across the
        // region, more so in Serbia), but the catalog only ever says "torba"
        // - with zero head-word matches for either, the head-word fallback
        // picks whichever token is longer and the search drifts onto an
        // unrelated product that merely shares a substring ("tašna za
        // laptop" was matching keyboards). Same root-collapse tradeoff as
        // "grej" above; "tasn" is not a substring of any other BCS word in
        // this catalog's vocabulary, so this is safe.
        $text = str_replace('tasn', 'torb', $text);

        // "Ranac" is the common Bosnian/Serbian word for the same laptop bag
        // products the catalog names "Ruksak za laptop".
        $text = preg_replace('/\bran(?:ac|ca|cu|cem|cevi|ceve|ceva|cima)\b/u', 'ruksak', $text);

        // Everyday local speech often says "suđe/sudje/sudja" where the
        // catalog says "posuđe". After diacritic stripping and dj->d those
        // become "sude/suda", so map the small dish-word family to the
        // catalog root before tokenization.
        $text = preg_replace('/\bsud(?:e|a|u|em|ima|ovi|ove|ova|ovima)?\b/u', 'posude', $text);

        // Anything that is not a letter or digit becomes a space, so "RG-6",
        // "RG6" and "RG 6" all tokenize alike.
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Words that carry no product meaning, in normalised (diacritic-free) form.
     *
     * Customers ask "Imate li grijalice na stanju?", not "grijalica". Without
     * this list every one of those words becomes a required search term and the
     * query matches nothing.
     *
     * @var string[]
     */
    private static $stopwords = [
        // conjunctions, prepositions, particles
        'i', 'u', 'na', 'za', 'je', 'se', 'da', 'li', 'sa', 's', 'od', 'do',
        'po', 'iz', 'ili', 'ali', 'ne', 'su', 'bi', 'ni', 'te', 'pa', 'a', 'o',
        // question words
        'koliko', 'kolika', 'kolko', 'gdje', 'gde', 'kada', 'kad', 'kako',
        'sta', 'sto', 'koji', 'koja', 'koje', 'kojo', 'zasto', 'cemu',
        // "jel"/"jeli" - colloquial one-word contraction of "je li" (is/does),
        // e.g. "jel ima racunara". Written as a single word, so splitting
        // into the already-stopword "je"/"li" never happens on its own -
        // found 2026-08-25 after it slipped through as a real search token
        // and matched "bijelo" (white) as a coincidental substring.
        'jel', 'jeli',
        // common verbs in enquiries
        'imate', 'imas', 'ima', 'imali', 'imam', 'imamo', 'imaju',
        // negated forms of the same verb ("nema akcija" = "there are no
        // deals") - just as generic as "ima", missing this let a whole
        // sentence collapse down to the single leftover token "nema", which
        // then got treated as a deliberate one-word product search.
        'nemate', 'nemas', 'nema', 'nemali', 'nemam', 'nemamo', 'nemaju',
        'kosta', 'kostaju', 'kosto',
        'trebam', 'treba', 'trebas', 'hocu', 'zelim', 'zelio', 'mozete',
        'moze', 'mogu', 'nadam', 'trazim', 'trazi', 'interesuje', 'zanima',
        'postoji', 'prodajete', 'nudite', 'saljete', 'saljite',
        'pokazi', 'pokaze', 'pokazes', 'pokazite', 'pokazao', 'pokazala',
        'pokazali', 'prikazi', 'prikaze', 'prikazes', 'prikazite',
        'ispisi', 'ispise', 'ispisite', 'izbaci', 'izbace', 'izbacite',
        'listaj', 'listajte', 'vrati', 'vratite', 'daj', 'dajte', 'ponudi', 'preporuci',
        // politeness and filler
        'molim', 'hvala', 'pozdrav', 'dobar', 'dobro', 'jutro', 'vece',
        'lijep', 'lijepo', 'me', 'mi', 'ti', 'vi', 'vas', 'nam', 'nas', 'ste',
        'sam', 'bih', 'teli', 'super', 'the', 'you', 'have', 'do', 'is', 'are', 'any',
        // greetings - found 2026-08-26 on zed.hr: "Bok, imate li kabele za
        // laptop?" had "bok" survive as a real search token, which then
        // joined the bucketKey() alongside "kabel"/"laptop" and broke the
        // exact-key alias match (bucketKey() sorts and joins every
        // meaningful token, so one stray extra word changes the whole key).
        // Any greeting opener has the same effect on any alias, not just
        // this one - it is a general gap, not specific to one bucket.
        'bok', 'zdravo', 'cao', 'hej', 'hello', 'hi',
        // shop words too generic to narrow anything
        'stanju', 'stanje', 'lageru', 'cijena', 'cijenu', 'cijene', 'cena',
        'komad', 'komada', 'komade', 'proizvod', 'proizvoda', 'proizvode',
        'proizvodi', 'artikl', 'artikal', 'artikla', 'artikle', 'artikli',
        'artikala', 'stvar', 'stvari', 'ponuda', 'ponudu',
        // "na prodaju" (for sale) - pure commerce filler, never itself the
        // product type. Missing this broke bucket resolution for full
        // sentences like "koliko racunara ima na prodaju" (worked fine for
        // the bare word "racunar" alone) - found 2026-08-25.
        'prodaju', 'prodaje', 'prodaja', 'prodajete', 'prodajemo',
        // vague follow-up words
        'neki', 'neke', 'neka', 'neku', 'neko', 'nesto', 'nesto',
        'ovaj', 'ova', 'ovo', 'ove', 'taj', 'ta', 'to', 'oni', 'one',
        'samo', 'onda', 'sad', 'idalje', 'dalje', 'uvijek', 'uvek',
        'jedan', 'jedna', 'jedno', 'nijedan', 'nijedna', 'nijedno',
        'ostal', 'ostala', 'ostale', 'ostali', 'ostalo', 'ostalih', 'ostalim',
        'drug', 'druga', 'druge', 'drugi', 'drugo', 'drugih', 'drugim',
        'jos', 'jeftinij', 'jeftinije', 'skuplj', 'skuplje',
        'iznad', 'preko', 'vise', 'manje',
        // "Poklon" (gift) names a USE CASE, not a product type - there is no
        // "poklon" product to find, and left in as a search token it
        // resolveToken()-shortens down to "poklo" (its stem "poklon" has no
        // catalog match, so the progressive-shortening fallback keeps
        // trimming), which then prefix-matches "Poklopac..." (lid/cover)
        // products - two completely unrelated words that only happen to
        // share five letters. Found 2026-08-27: "poklon za muža, do 100 KM"
        // returned a vacuum cleaner brush cover, a cable channel cover, and
        // a glass lid. Stripping it here leaves the rest of the sentence
        // (recipient, budget) to drive the search instead.
        'poklon', 'poklona', 'poklone', 'poklonu', 'poklonom',
        // currency
        'km', 'bam', 'maraka', 'marke', 'maraka', 'eur', 'eura', 'kn',
        'rsd', 'din', 'dinar', 'dinara', 'dinari',
        // "on the site right now" filler - refers to the store, not a product
        'trenutno', 'trenutacno', 'sada', 'stranici', 'stranica', 'stranicu',
        'stranice', 'sajtu', 'sajt', 'sajta',
        // "for the house/room/apartment" - pure scope filler in this
        // catalog (nothing is tagged by intended room), but a completely
        // natural, common thing for a real customer to add ("alarm ZA
        // KUĆU", "čistač zraka ZA SOBU"). Found 2026-08-27: both of those
        // real phrasings broke bucket resolution (the extra word changes
        // bucketKey()'s combined key) and fell through to plain token
        // search, where "kuću"/"sobu" then won the head-word race over the
        // actual product word ("alarmni sistem za kucu" returned a LEGO
        // house set and a PC case; "čistač zraka za sobu" returned window
        // cleaners). Deliberately NOT "kucni"/"kucna" (stems to "kucn",
        // already a real, distinguishing word elsewhere - "Punjač kućni"
        // vs "Punjač auto") - only the noun forms of "house" itself. The
        // Bosnian stemmer does not unify "kuća" case forms (kuca/kucu/
        // kuce/kuci/kucom each stem to themselves, unchanged), so all five
        // are listed rather than one stem covering all of them.
        'kuca', 'kucu', 'kuce', 'kuci', 'kucom',
        'soba', 'sobu', 'sobe', 'sobi', 'sobom',
        'stan', 'stana', 'stanu', 'stanom',
        'dom', 'doma', 'domu', 'domom',
    ];

    /**
     * Split a normalised string into useful search tokens.
     *
     * @param string $text
     * @param int    $minLength     Tokens shorter than this are dropped.
     * @param bool   $dropStopwords Remove conversational filler words.
     * @return string[]
     */
    public static function tokens($text, $minLength = 2, $dropStopwords = true)
    {
        $tokens = $dropStopwords
            ? self::meaningfulTokens($text, $minLength)
            : self::rawTokens($text, $minLength);

        // If filtering removed everything, the customer typed only filler.
        // Fall back to the unfiltered tokens rather than searching for nothing.
        if ($tokens === []) {
            return self::rawTokens($text, $minLength);
        }

        return $tokens;
    }

    /**
     * Tokens after stopword filtering, without falling back to filler words.
     *
     * Use this to decide whether a message contains real product meaning. For
     * example "ima li neke do 600 KM" should return no meaningful tokens, so
     * the chat can reuse the previous product context.
     *
     * @param string $text
     * @param int    $minLength
     * @return string[]
     */
    public static function meaningfulTokens($text, $minLength = 2)
    {
        $tokens = [];
        foreach (self::rawTokens($text, $minLength) as $token) {
            if (!in_array($token, self::$stopwords, true)) {
                $tokens[] = $token;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Raw normalised tokens, with no stopword filtering.
     *
     * @param string $text
     * @param int    $minLength
     * @return string[]
     */
    private static function rawTokens($text, $minLength)
    {
        $normalized = self::canonicalProductWords(self::normalize($text));
        if ($normalized === '') {
            return [];
        }

        $tokens = [];
        foreach (explode(' ', $normalized) as $token) {
            if (mb_strlen($token) >= $minLength) {
                $tokens[] = $token;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Collapse common product-word variants before search tokenisation.
     *
     * Bosnian has a funny trap here: "miševe" may mean computer mice, but it
     * also appears in products such as "rastjerivač miševa i pacova". For an
     * electronics webshop assistant, a bare "pokaži mi miševe" should mean PC
     * mice. Watches have a similar problem: "satove" can drift into knife
     * brands/models such as Satoru/Satake unless it is collapsed to "sat".
     *
     * If the customer explicitly mentions traps, rodents, pacovi or repellents,
     * keep the literal rodent meaning.
     *
     * @param string $normalized Already passed through normalize().
     * @return string
     */
    private static function canonicalProductWords($normalized)
    {
        if ($normalized === '') {
            return '';
        }

        $rodentContext = preg_match(
            '/\b(?:rastjerivac|rastjerivaci|rastjerivanje|zamka|zamke|klopka|klopke|pacov|pacova|pacove|glodar|glodara|glodare|ultrazvucni|ultrazvucan)\b/u',
            $normalized
        );

        if (!$rodentContext) {
            $normalized = preg_replace(
                '/\b(?:misa|misem|misev|misevi|miseve|miseva|misevima|mouse|mice)\b/u',
                'mis',
                $normalized
            );
        }

        // "Kod vas/kod nas" means "in your/our shop", not a discount code
        // and not a product keyword. Strip the phrase as a unit so brand-only
        // questions like "sta je Roborock kod vas" can browse that brand.
        $normalized = preg_replace('/\bkod\s+(?:vas|nas)\b/u', ' ', $normalized);

        $normalized = preg_replace(
            '/\b(?:smartwatch|smart\s+watch|smart\s+sat\w*|rucn\w*\s+sat\w*|sat\w*\s+za\s+ruku)\b/u',
            'pametni sat',
            $normalized
        );

        $normalized = preg_replace(
            '/\b(?:satovi|satove|satova|satovima|satom|watch)\b/u',
            'sat',
            $normalized
        );

        // Common local spelling drift: the category is "Usisavači", but people
        // often type "usisivac". Map both forms to the catalogue's main word
        // so "usisivac na akciji" browses the whole vacuum category instead of
        // matching only the one product that happens to use that spelling.
        $normalized = preg_replace(
            '/\b(?:usisivac|usisivaci|usisivace|usisivaca|usisivacem|usisivacima)\b/u',
            'usisavac',
            $normalized
        );

        // Product names store this as "Anti-Virus" (two words once the
        // hyphen becomes a space in normalize()), but customers type it as
        // one word.
        $normalized = preg_replace('/\bantivirus\w*\b/u', 'anti virus', $normalized);

        // "buzilica" is a very common misspelling of "bušilica" (drill).
        $normalized = preg_replace('/\bbuzilic(\w*)\b/u', 'busilic$1', $normalized);

        // Products say "tekućina", customers say "tečnost" - same thing.
        $normalized = preg_replace('/\btecnost\w*\b/u', 'tekucina', $normalized);

        // "Tiganj" is at least as common a word as "tava" for the same pan -
        // found 2026-08-27 matching a LEGO tiger toy instead ("tiganj" and
        // "tigar" are close enough in letters to fuzzy-match), since no real
        // product name says "tiganj" at all.
        $normalized = preg_replace('/\btiganj\w*\b/u', 'tava', $normalized);
        $normalized = preg_replace('/\btignj\w*\b/u', 'tava', $normalized);

        // "Rerna" is the common Serbian/regional word for "pećnica" (oven) -
        // found 2026-08-27, "mini rerna" matched LEGO sets instead of the
        // real "Mini pećnica..." products.
        $normalized = preg_replace('/\brern\w*\b/u', 'pecnica', $normalized);

        // Customers write MacBook phonetically ("mek buk") or with a common
        // extra-k typo ("mackbook"). The catalog has MacBook in the model
        // field while the product name is the generic "Laptop...", so include
        // both anchors and keep the search inside the real laptop path.
        $normalized = preg_replace('/\b(?:macbook|mackbook|mckbook)\w*\b/u', 'laptop macbook', $normalized);
        $normalized = preg_replace('/\bmek\s+buk\w*\b/u', 'laptop macbook', $normalized);
        $normalized = preg_replace('/\bmekbuk\w*\b/u', 'laptop macbook', $normalized);

        // "Igranje" (playing/gaming, gerund of "igra") stems to "igranj",
        // which resolveToken()'s progressive shortening then trims all the
        // way down to "igra" (its minimum length floor) once nothing longer
        // matches - and "igra" is the literal head word of every "Igra
        // PlayStation..."/"Igra za Nintendo..." product. Same collision for
        // the plain noun "igre" (games) - it stems to itself ("igre",
        // already under stem()'s 5-char floor) but still gets shortened
        // down to the 3-char floor "igr", broad enough to prefix-match both
        // "Igra..." AND "Igraća konzola..." (consoles). So "X za igranje"/
        // "X za igre" for ANY product type (motherboard, graphics card,
        // keyboard, mouse, headphones, monitor - found 2026-08-27, systemic
        // across the whole gaming-peripheral space) resolved down to actual
        // game software or a console instead of the accessory asked for.
        //
        // Tried replacing with the catalog's own loanword "gaming" instead
        // of stripping (real products do use it - "Slušalice sa
        // mikrofonom, gaming...") - REJECTED: "gaming" itself then
        // dominates headToken() selection strongly enough to occasionally
        // outrank the actual product-type word for types with no
        // "gaming"-branded stock at all ("grafička kartica za igre" started
        // returning gaming headphones instead of any graphics card).
        // Stripping is strictly safer: the bare product word alone already
        // returns reasonable results (a mix of general and gaming-branded
        // items, never a game or console) for every type checked.
        $normalized = preg_replace('/\bza\s+igranj\w*\b/u', ' ', $normalized);
        $normalized = preg_replace('/\bigranj\w*\b/u', ' ', $normalized);
        $normalized = preg_replace('/\bza\s+igr\w*\b/u', ' ', $normalized);

        // Localized "gejmerski" is the same product qualifier as the
        // catalog's own English "gaming" ("Monitor ..., gaming", "Miš
        // optički, gaming"). Without this, "monitor gejmerski" was treated
        // as a plain monitor query and a humidity monitor could slip into
        // the top results ahead of actual gaming monitors.
        $normalized = preg_replace('/\bgejmer\w*\b/u', 'gaming', $normalized);

        // "Gaming stol/sto" means a desk/table, not "gaming stolica"
        // (chair) and not any random product with the word gaming. The
        // catalog's real matching products are named "Radni sto/stol...".
        $normalized = preg_replace(
            '/\bgaming\s+(?:sto|stol)\b/u',
            'radni sto pc',
            $normalized
        );

        // Customers often shorten "radni sto za računar/PC" to just "sto za
        // računar", and may add "za igranje" (stripped just above). Product
        // names use "Radni sto/stol...", not "računar", so rewrite the
        // shorthand to the catalog's own anchor words.
        $normalized = preg_replace(
            '/\b(?:sto|stol)\s+(?:za\s+)?(?:pc|racunar\w*|kompjuter\w*)\b/u',
            'radni sto pc',
            $normalized
        );

        // There is no separate keyboard mat bucket in this catalog; the real
        // products customers mean here are large "Podloga za miš" desk/mouse
        // pads. Leaving "tastatura" in place makes ordinary keyboards win.
        $normalized = preg_replace(
            '/\bpodlog\w*\s+(?:za\s+)?tastatur\w*\b/u',
            'podloga za mis',
            $normalized
        );

        // Natural accessory phrasings where the device qualifier otherwise
        // becomes the dominant search word and returns the device itself.
        $normalized = preg_replace('/\bhladenj\w*\b/u', 'hladnjak', $normalized);
        if (preg_match('/\bdocking\w*\b/u', $normalized)) {
            $normalized = preg_replace('/\blaptop\w*\b/u', ' ', $normalized);
        }

        // Type-C is implied by most real USB HUB products here and can be a
        // brittle leftover token; "usb hub" alone already finds the right
        // bucket. Keep this scoped to hub queries so regular Type-C adapters
        // still use the connector word.
        if (preg_match('/\bhub\w*\b/u', $normalized)) {
            $normalized = preg_replace('/\btype\s+c\b/u', ' ', $normalized);
        }

        // Printer consumables: customers say "boja za printer", but product
        // names say "Tinta..."; "printer" itself is only the device and
        // should not outrank toner/ink/paper consumables.
        if (preg_match('/\b(?:toner\w*|tint\w*)\b/u', $normalized)) {
            $normalized = preg_replace('/\b(?:printer\w*|stampac\w*)\b/u', ' ', $normalized);
        }
        if (preg_match('/\bboj\w*\b/u', $normalized)
            && preg_match('/\b(?:printer\w*|stampac\w*)\b/u', $normalized)
        ) {
            $normalized = preg_replace('/\bboj\w*\b/u', 'tinta', $normalized);
            $normalized = preg_replace('/\b(?:printer\w*|stampac\w*)\b/u', ' ', $normalized);
        }

        // A bare "skener" usually means multifunction printer/scanner
        // devices in this catalog; real product names include "Printer /
        // kopir / skener", so include the product's leading word.
        $normalized = preg_replace('/\bskener\w*\b/u', 'printer skener', $normalized);

        // "SSD/HDD/disk za laptop" is normally a request for the separate
        // storage drive, not for laptops that already contain an SSD. The
        // storage products live under HDD/SSD and do not mention laptop in
        // their names, so strip that device qualifier once the storage word
        // is present.
        if (preg_match('/\b(?:ssd|hdd|disk\w*)\b/u', $normalized)) {
            $normalized = preg_replace('/\blaptop\w*\b/u', ' ', $normalized);
        }

        // "Odbojnik" (repellent/deterrent device) is a natural synonym for
        // "rastjerivač" - real products are all "Ultrazvučni rastjerivač za
        // komarce/krtice...". Found 2026-08-27.
        $normalized = preg_replace('/\bodbojnik\w*\b/u', 'rastjerivac', $normalized);

        // "Lonac pod pritiskom" (the literal, descriptive phrase) means the
        // same thing as "ekspres lonac" (the idiom real products use) - a
        // customer who does not know the idiom types the literal version
        // instead. Found 2026-08-27 matching e-cigarette pods and stove
        // parts via "pod"/"pritiskom" individually.
        $normalized = preg_replace('/\blona\w*\s+pod\s+pritisk\w*\b/u', 'ekspres lonac', $normalized);

        return trim(preg_replace('/\s+/u', ' ', $normalized));
    }

    /**
     * Reduce a word to a stem that survives Bosnian inflection.
     *
     * Fulltext wildcards only extend forwards: "laptope*" matches words
     * BEGINNING with "laptope", so it never finds a product called "laptop".
     * Bosnian inflects heavily — laptop/laptopa/laptope/laptopu,
     * mobitel/mobiteli, slušalica/slušalice — so the customer's ending is
     * often longer than the catalogue's.
     *
     * Trimming trailing vowels puts both forms on a common prefix, which the
     * wildcard then matches in either direction. Short words are left alone:
     * over-trimming turns them into prefixes that match half the catalogue.
     *
     * @param string $token Already normalised (lowercase, no diacritics).
     * @return string
     */
    public static function stem($token)
    {
        $vowels = ['a', 'e', 'i', 'o', 'u'];

        if (mb_strlen($token) < 5) {
            return $token;
        }

        $stem = $token;
        for ($i = 0; $i < 2; $i++) {
            if (mb_strlen($stem) <= 4) {
                break;
            }
            if (!in_array(mb_substr($stem, -1), $vowels, true)) {
                break;
            }
            $stem = mb_substr($stem, 0, -1);
        }

        return $stem;
    }

    /**
     * Pull price filters out of a natural question.
     *
     * "Imate li klima uređaj do 1000 KM?" must search for "klima uređaj" with a
     * 1000 KM cap — not for the words "1000" and "KM", which match unrelated
     * products. Specification numbers ("televizor 55 inča", "klima 12000 Btu")
     * are left alone, because only a number introduced by do/ispod/iznad/preko
     * is treated as money.
     *
     * @param string $query
     * @return array{query:string,max_price:float|null,min_price:float|null,target_price:float|null}
     */
    public static function extractBudget($query)
    {
        $query = (string) $query;
        $max   = null;
        $min   = null;
        $target = null;

        $maxPattern = '/\b(?:do|ispod|maksimalno|maksimum|maks|max|manje\s+od|jeftinije\s+od)'
                 . '\s+([0-9][0-9\s.,]*)\s*(?:km|bam|marak\w*|km\.|eur\w*|rsd|din\w*|kn\b)?/iu';

        if (preg_match($maxPattern, $query, $m)) {
            $amount = self::parseAmount($m[1]);
            if ($amount > 0) {
                $max   = $amount;
                $query = trim(preg_replace($maxPattern, ' ', $query));
            }
        }

        $minPattern = '/\b(?:iznad|preko|vise\s+od|više\s+od|vece\s+od|veće\s+od|skuplje\s+od|minimalno|minimum|min)'
                  . '\s+([0-9][0-9\s.,]*)\s*(?:km|bam|marak\w*|km\.|eur\w*|rsd|din\w*|kn\b)?/iu';

        if (preg_match($minPattern, $query, $m)) {
            $amount = self::parseAmount($m[1]);
            if ($amount > 0) {
                $min   = $amount;
                $query = trim(preg_replace($minPattern, ' ', $query));
            }
        }

        if ($max === null && $min === null) {
            $targetPattern = '/\b(?:oko|otprilike|priblizno|približno|cca|ca|budzet|budžet|za|cijena|cijene|kosta|kostaju|su|je)'
                         . '\s+([0-9][0-9\s.,]*)\s*(?:km|bam|marak\w*|km\.|eur\w*|rsd|din\w*|kn\b)(?!\s*\/?\s*h\b)/iu';

            if (preg_match($targetPattern, $query, $m)) {
                $amount = self::parseAmount($m[1]);
                if ($amount > 0) {
                    $target = $amount;
                    $max    = $amount;
                    $query  = trim(preg_replace($targetPattern, ' ', $query));
                }
            }
        }

        if ($max === null && $min === null) {
            $bareCurrencyPattern = '/\b([0-9][0-9\s.,]*)\s*(?:km|bam|marak\w*|km\.|eur\w*|rsd|din\w*|kn\b)(?!\s*\/?\s*h\b)/iu';

            if (preg_match($bareCurrencyPattern, $query, $m)) {
                $amount = self::parseAmount($m[1]);
                // Bare "30 km" is often distance/range, not money. Larger
                // amounts in this shop context are usually a price/budget.
                if ($amount >= 100) {
                    $target = $amount;
                    $max    = $amount;
                    $query  = trim(preg_replace($bareCurrencyPattern, ' ', $query));
                }
            }
        }

        return [
            'query'        => $query,
            'max_price'    => $max,
            'min_price'    => $min,
            'target_price' => $target,
        ];
    }

    /**
     * Pull sorting intent out of a natural question.
     *
     * "Pokaži mi najjeftinije monitore" should search for monitors and sort by
     * price ascending. The sort words are not useful product terms, so they are
     * removed from the query before tokenisation.
     *
     * "Najveća akcija" / "najveći popust" means sort by discount percent,
     * not by the most expensive product.
     *
     * @param string $query
     * @return array{query:string,sort:string|null} sort is price_asc, price_desc or discount_desc.
     */
    public static function extractSortIntent($query)
    {
        $query = (string) $query;
        $sort  = null;

        $discountDescPattern = '/\b(?:najve[cć]\w*\s+(?:akcij\w*|popust\w*|sni(?:z|ž)en\w*|sniz\w*)|najvi[sš]\w*\s+(?:sni(?:z|ž)en\w*|sniz\w*|popust\w*)|najbolj\w*\s+(?:akcij\w*|popust\w*)|najve[cć]\w*\s+procen\w*\s+popust\w*)\b/iu';
        $ascPattern = '/\b(?:najjeftin\w*|najeftin\w*|jeftinij\w*|povoljnij\w*|najpovoljn\w*|najmanj\w*\s+cijen\w*|najniz\w*\s+cijen\w*|najniž\w*\s+cijen\w*|od\s+najjeftinij\w*|od\s+najeftinij\w*)\b/iu';
        $descPattern = '/\b(?:najskuplj\w*|najskulplj\w*|skuplj\w*|skulplj\w*|najvis\w*\s+cijen\w*|najviš\w*\s+cijen\w*|od\s+najskuplj\w*|od\s+najskulplj\w*)\b/iu';

        if (preg_match($discountDescPattern, $query)) {
            $sort = 'discount_desc';
            $query = preg_replace($discountDescPattern, ' ', $query);
        }

        if (preg_match($ascPattern, $query)) {
            $sort = 'price_asc';
            $query = preg_replace($ascPattern, ' ', $query);
        }

        if (preg_match($descPattern, $query)) {
            $sort = 'price_desc';
            $query = preg_replace($descPattern, ' ', $query);
        }

        return [
            'query' => trim(preg_replace('/\s+/u', ' ', $query)),
            'sort'  => $sort,
        ];
    }

    /**
     * Read a human-written amount: "1000", "1.500", "1 500", "1500,00".
     *
     * @param string $raw
     * @return float
     */
    private static function parseAmount($raw)
    {
        $clean = preg_replace('/\s+/u', '', trim($raw));

        // European thousands grouping, e.g. 1.500 or 1,500 — separators only.
        if (preg_match('/^[0-9]{1,3}([.,][0-9]{3})+$/', $clean)) {
            return (float) preg_replace('/[.,]/', '', $clean);
        }

        // Otherwise a comma is a decimal point.
        $clean = str_replace(',', '.', $clean);

        return is_numeric($clean) ? (float) $clean : 0.0;
    }

    /**
     * Parse a numeric string from the API.
     *
     * The feed formats numbers as "19,869.00" — a comma thousands separator.
     * PHP's float cast stops at the comma, so (float)"19,869.00" is 19.0 and
     * stock levels silently become nonsense. Always route feed numbers here.
     *
     * @param mixed $value
     * @return float
     */
    public static function parseNumber($value)
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $clean = str_replace(',', '', trim((string) $value));
        if ($clean === '' || !is_numeric($clean)) {
            return 0.0;
        }

        return (float) $clean;
    }

    /**
     * Parse a weight such as "0.291 kg" into kilograms.
     *
     * @param mixed $value
     * @return float|null  Null when the field is empty or unparseable.
     */
    public static function parseWeightKg($value)
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (!preg_match('/-?[\d,]*\.?\d+/', $raw, $m)) {
            return null;
        }

        return self::parseNumber($m[0]);
    }
}
