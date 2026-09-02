<?php
/**
 * Assembles the system prompt from two files.
 *
 *   prompts/system_prompt.txt  — how the assistant behaves. Technical; changes
 *                                rarely; edited by whoever maintains the code.
 *   prompts/faq.txt            — what the assistant knows about the store.
 *                                Plain facts; changes often; safe for anyone
 *                                at D-Store to edit without breaking anything.
 *
 * Splitting them means colleagues can add a phone number or a delivery time
 * without touching the instructions that keep the bot on-topic and honest.
 *
 * Target: PHP 7.4.
 */
class PromptLoader
{
    /**
     * @param string $dir Directory holding the prompt files.
     * @return string
     * @throws RuntimeException When the behaviour prompt is missing.
     */
    public static function load($dir)
    {
        $dir = rtrim($dir, "/" . DIRECTORY_SEPARATOR);

        $behaviourPath = $dir . DIRECTORY_SEPARATOR . 'system_prompt.txt';
        if (!is_file($behaviourPath)) {
            throw new RuntimeException("Missing system prompt at {$behaviourPath}");
        }

        $prompt = (string) file_get_contents($behaviourPath);

        // The FAQ is optional — the bot still works without it, it just knows
        // less and escalates more.
        //
        // Each deployment can point at its own faq file via config's
        // 'faq_file' (e.g. 'faq.zed.txt') - falls back to the shared
        // faq.txt (Digitalis/D-Store) when absent, so existing
        // deployments need no change. Added 2026-08-27: zed.hr and
        // optibox.rs were silently sharing Digitalis's faq.txt verbatim -
        // wrong phone numbers, wrong address, wrong delivery/return
        // policy, and a BiH-only pensioner financing program neither site
        // actually runs (both already set pension_financing_available to
        // false, but the shared faq.txt text had no way to respect that).
        $faqFile = (string) config_get('faq_file', 'faq.txt');
        $faqPath = $dir . DIRECTORY_SEPARATOR . $faqFile;
        if (is_file($faqPath)) {
            $faq = self::stripComments((string) file_get_contents($faqPath));
            if (trim($faq) !== '') {
                $prompt .= "\n\n"
                    . "===========================================================================\n"
                    . "STORE INFORMATION\n"
                    . "===========================================================================\n\n"
                    . $faq;
            }
        }

        return self::substitutePlaceholders($prompt);
    }

    /**
     * Fills in {{store_name}}-style tokens from this deployment's own config,
     * so the same system_prompt.txt/faq.txt text works correctly across all
     * four sites instead of hardcoding one brand's name/phone/email. Found
     * 2026-08-25 while testing the real AI for the first time — mock mode
     * never rendered this file's text, so the hardcoded "D-Store" branding
     * had gone unnoticed until a live reply actually showed it.
     *
     * @param string $text
     * @return string
     */
    private static function substitutePlaceholders($text)
    {
        $values = [
            '{{store_name}}'      => (string) config_get('store_name', 'Digitalis'),
            '{{assistant_name}}'  => (string) config_get('assistant_name', 'Digitalis AI'),
            '{{company_name}}'    => (string) config_get('company_name', 'Digitalis'),
            '{{support_phone}}'   => (string) config_get('support_phone', ''),
            '{{support_email}}'   => (string) config_get('support_email', ''),
            '{{currency}}'        => (string) config_get('currency', 'KM'),
        ];

        return str_replace(array_keys($values), array_values($values), $text);
    }

    /**
     * Remove editing notes so they are not sent to the model as facts.
     *
     * Lines starting with # are instructions for the person editing the file,
     * not information for customers.
     *
     * @param string $text
     * @return string
     */
    private static function stripComments($text)
    {
        $kept = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            if (preg_match('/^\s*#/u', $line)) {
                continue;
            }
            $kept[] = $line;
        }

        // Collapse the blank runs left behind by removed comment blocks.
        $joined = implode("\n", $kept);
        return trim(preg_replace('/\n{3,}/u', "\n\n", $joined));
    }
}
