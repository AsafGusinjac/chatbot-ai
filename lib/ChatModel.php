<?php
/**
 * What ChatService needs from a language model.
 *
 * Exists so a fake model can be swapped in for testing without touching any
 * other code. OpenAiApi is the production implementation; MockChatModel is
 * the offline one.
 *
 * Target: PHP 7.4.
 */
interface ChatModel
{
    /**
     * Produce a reply, optionally calling tools along the way.
     *
     * @param array    $messages     Conversation so far.
     * @param string   $systemPrompt
     * @param array    $tools        Tool definitions.
     * @param callable $executor     fn(string $name, array $input): string
     * @param int      $maxTokens
     * @param int      $maxRounds
     * @param bool     $forceToolUse When true, the FIRST round must call a
     *                       tool rather than answer directly - for messages
     *                       where the caller already knows an answer from
     *                       memory would be a guess (a product/price
     *                       question), so the model cannot skip the real
     *                       lookup even if it thinks it can wing it.
     * @return string Final reply text.
     */
    public function chatWithTools(array $messages, $systemPrompt, array $tools, $executor, $maxTokens = 1024, $maxRounds = 4, $forceToolUse = false);
}
