<?php
/**
 * Fast rule-based scope guard for obvious non-store messages.
 *
 * The model still has the final instruction in the system prompt, but the
 * common junk traffic should not cost an API call at all. This catches the
 * easy cases: small talk, jokes, poems, homework, translation, weather, news,
 * politics, medical/legal questions, recipes and coding help.
 *
 * Target: PHP 7.4.
 */
class ScopeGuard
{
    /**
     * @param string $message
     * @return string|null A professional scope reply, or null when the message
     *                     should continue to the normal bot flow.
     */
    public static function answer($message)
    {
        $norm = Text::normalize($message);

        if ($norm === '') {
            return null;
        }

        // Store use of "vrijeme" must stay allowed.
        if (preg_match('/\b(radno vrijeme|kada radite|kad radite|jeste li otvoreni)\b/u', $norm)) {
            return null;
        }

        // "Ušla sam bezveze da vidim šta ima kod vas" is not off-topic small
        // talk; it is a browsing/shopping opener. Let the normal AI/catalog
        // flow welcome the customer and suggest what we carry.
        if (self::looksLikeStoreBrowsing($norm)) {
            return null;
        }

        // Questions about the assistant's own identity ("who/what are you")
        // get a specific answer instead of the generic scope redirect.
        // "ko"/"sta" are Bosnian/Serbian; "tko"/"sto" are the Croatian
        // equivalents of the same words after diacritic-stripping ("što" ->
        // "sto") — zed.hr traffic uses these, so both forms are matched.
        $identityPatterns = [
            '/\b(ko si|ko si ti|tko si|tko si ti|sta si|sta si ti|sto si|sto si ti|kako se zoves)\b/u',
            '/\bjesi li (ti )?(robot|covjek|covek|ai|bot|vjestacka inteligencija|masina)\b/u',
            '/\b(sta|sto) (sve )?ti mozes\b/u',
            '/\b(sta|sto) ti (radis|umijes|znas)\b/u',
        ];

        foreach ($identityPatterns as $pattern) {
            if (preg_match($pattern, $norm)) {
                return self::identityMessage();
            }
        }

        $patterns = [
            '/\b(kako si|sta ima|sto ima|sta radis|sto radis)\b/u',
            '/\b(mozes li pricati|haj pricaj|pricaj sa mnom|dosadno mi je)\b/u',
            '/\b(ispricaj|reci|napisi|daj)\b.*\b(vic|viceva|salu|pjesmu|pesmu|pricu|story|joke|poem)\b/u',
            '/\b(prevedi|translate|domaci|zadaca|zadatak|rijesi|resi|izracunaj|matematika)\b/u',
            '/\b(kakvo je vrijeme|vremenska prognoza|prognoza|politika|vijesti|news)\b/u',
            '/\b(doktor|lijek|bolest|advokat|zakon|ugovor)\b/u',
            // "kuhanje", "veza" and "putovanje" were dropped from here -
            // found 2026-08-26 they were blocking genuine product questions
            // as off-topic small talk: "nesto za kuhanje kafe" (a coffee
            // maker), "kakva je veza sa internetom" (a router's
            // connection), "adapter za putovanje" (a travel adapter) - all
            // real things this store sells, not recipe/relationship/travel
            // chat. "recept"/"hotel"/"ljubav" have no plausible electronics
            // meaning and stay.
            '/\b(recept|hotel|ljubav)\b/u',
            // Coding/programming help, any inflection: programirati, programiranje,
            // programer, kodirati, kodiranje, kod napisati...
            '/\bprogramir\w*\b/u',
            '/\bkodir\w*\b/u',
            // "kod" is deliberately excluded from the general form below - it also
            // means "discount code" in Bosnian ("kod za popust"), which must stay
            // a store question. Only "napisi kod" NOT followed by "za" (which would
            // signal "code for X" as in a coupon) counts as a coding request.
            '/\b(napisi|napravi)\b.*\b(program|skriptu|script|funkciju)\b/u',
            '/\bnapis\w* (mi )?kod\b(?! za)/u',
            '/\b(umijes|znas) li (ti )?(programirati|kodirati|pjevati|crtati|kuhati|voziti)\b/u',
            '/^(hello|hey|cao|bok|zdravo|hi)$/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $norm)) {
                return self::message();
            }
        }

        return null;
    }

    /**
     * @return string
     */
    private static function message()
    {
        $storeName = (string) config_get('store_name', config_get('company_name', 'Digitalis'));

        return "Tu sam da pomognem sa pitanjima o {$storeName} artiklima, "
            . 'cijenama, stanju, dostavi, placanju, garanciji i povratima. '
            . 'Sta trazite?';
    }

    /**
     * @return string
     */
    private static function identityMessage()
    {
        $assistantName = (string) config_get('assistant_name', 'Digitalis AI');
        $companyName   = rtrim((string) config_get('company_name', 'Digitalis'), '.');

        return "Ja sam {$assistantName}, virtuelni asistent kompanije {$companyName}. "
            . 'Tu sam da vam pomognem u pronalaženju proizvoda, odgovorim na '
            . 'pitanja o našim uslugama i pružim podršku tokom kupovine. '
            . 'Kako vam mogu pomoći danas?';
    }

    /**
     * @param string $norm Already normalized.
     * @return bool
     */
    private static function looksLikeStoreBrowsing($norm)
    {
        $hasBrowsing = preg_match('/\b(?:vidim|pogledam|pogledati|razgledam|razgledati|usla|usao|dosla|dosao|svratila|svratio)\b/u', $norm) === 1;
        $hasStoreContext = preg_match('/\b(?:kod\s+vas|kod\s+nas|stranic\w*|sajt\w*|web\w*|ponud\w*|artikl\w*|proizvod\w*)\b/u', $norm) === 1;

        return $hasBrowsing && $hasStoreContext;
    }
}
