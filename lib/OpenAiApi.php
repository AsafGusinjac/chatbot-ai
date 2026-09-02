<?php
require_once __DIR__ . '/ChatModel.php';
require_once __DIR__ . '/ChatApiException.php';

/**
 * OpenAI Chat Completions client.
 *
 * Implements the same ChatModel interface used by ChatService and the mock
 * testing model.
 *
 * Tool definitions are accepted in the SAME shape ChatService already produces
 * ({name, description, input_schema}) and converted to OpenAI's nested
 * {type:"function", function:{...}} form here. Keeping the translation inside
 * this class means adding a third provider later touches nothing else.
 *
 * Target: PHP 7.4.
 */
class OpenAiApi implements ChatModel
{
    const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /** @var string */
    private $apiKey;

    /** @var string */
    private $model;

    /** @var int */
    private $timeout;

    /** @var bool */
    private $useMaxCompletionTokens;

    /** @var float|null */
    private $temperature;

    /** @var string|null */
    private $reasoningEffort;

    /**
     * @param string      $apiKey
     * @param string      $model  Check platform.openai.com/docs/models for
     *                       what is current — model names change often, so
     *                       this is configurable rather than hard-coded.
     * @param int         $timeout
     * @param bool        $useMaxCompletionTokens Newer models reject
     *                       `max_tokens` and require `max_completion_tokens`.
     *                       If a request fails complaining about that
     *                       parameter, set this.
     * @param float|null  $temperature 0-2, lower = more deterministic/
     *                       repeatable answers, higher = more varied. Null
     *                       leaves it unset and OpenAI uses its own default
     *                       (1.0).
     * @param string|null $reasoningEffort Reasoning-tier models (found
     *                       2026-08-26 with gpt-5.6-luna) reject function
     *                       tools outright unless this is explicitly set to
     *                       'none' - the API error is literally "Function
     *                       tools with reasoning_effort are not supported...
     *                       set reasoning_effort to 'none'". Null leaves it
     *                       unset for models that do not use this parameter
     *                       at all (gpt-4o family) and would reject it.
     */
    public function __construct($apiKey, $model = 'gpt-4o', $timeout = 60, $useMaxCompletionTokens = false, $temperature = null, $reasoningEffort = null)
    {
        $this->apiKey                 = $apiKey;
        $this->model                  = $model;
        $this->timeout                = $timeout;
        $this->useMaxCompletionTokens = (bool) $useMaxCompletionTokens;
        $this->temperature            = $temperature !== null ? (float) $temperature : null;
        $this->reasoningEffort        = $reasoningEffort !== null && $reasoningEffort !== '' ? (string) $reasoningEffort : null;
    }

    /**
     * @param array    $messages
     * @param string   $systemPrompt
     * @param array    $tools     Tool definitions in ChatService's internal shape.
     * @param callable $executor
     * @param int      $maxTokens
     * @param int      $maxRounds
     * @param bool     $forceToolUse Force a tool call on the first round -
     *                       see ChatModel::chatWithTools().
     * @return string
     * @throws ChatApiException
     */
    public function chatWithTools(array $messages, $systemPrompt, array $tools, $executor, $maxTokens = 1024, $maxRounds = 4, $forceToolUse = false)
    {
        // OpenAI has no separate system field — it is the first message.
        $working = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($messages as $m) {
            $working[] = $m;
        }

        $openAiTools = $this->convertTools($tools);

        for ($round = 0; $round < $maxRounds; $round++) {
            $payload = [
                'model'    => $this->model,
                'messages' => $working,
            ];

            if ($openAiTools !== []) {
                $payload['tools'] = $openAiTools;
                // Only the first round is forced - once a tool has actually
                // run, the model must be free to just answer from the
                // result instead of being made to call something again.
                if ($forceToolUse && $round === 0) {
                    $payload['tool_choice'] = 'required';
                }
            }

            if ($this->temperature !== null) {
                $payload['temperature'] = $this->temperature;
            }

            if ($this->reasoningEffort !== null) {
                $payload['reasoning_effort'] = $this->reasoningEffort;
            }

            if ($this->useMaxCompletionTokens) {
                $payload['max_completion_tokens'] = $maxTokens;
            } else {
                $payload['max_tokens'] = $maxTokens;
            }

            $response = $this->post($payload);

            if (!isset($response['choices'][0]['message'])) {
                throw new ChatApiException('Response contained no message.', 'bad_response');
            }

            $message      = $response['choices'][0]['message'];
            $finishReason = isset($response['choices'][0]['finish_reason'])
                ? $response['choices'][0]['finish_reason']
                : '';

            if ($finishReason !== 'tool_calls' || empty($message['tool_calls'])) {
                $text = isset($message['content']) ? trim((string) $message['content']) : '';
                if ($text === '') {
                    throw new ChatApiException('Response contained no text.', 'empty_response');
                }
                return $text;
            }

            // Echo the assistant turn back verbatim — it carries the tool_calls
            // the tool results are matched against.
            $working[] = $message;

            foreach ($message['tool_calls'] as $call) {
                $name = isset($call['function']['name']) ? $call['function']['name'] : '';

                // Arguments arrive as a JSON string. Forgetting to decode means the tool is
                // called with nothing and silently returns no products.
                $rawArgs = isset($call['function']['arguments']) ? $call['function']['arguments'] : '{}';
                $args    = json_decode((string) $rawArgs, true);
                if (!is_array($args)) {
                    $args = [];
                }

                try {
                    $result = call_user_func($executor, $name, $args);
                } catch (Throwable $e) {
                    error_log("Tool '{$name}' failed: " . $e->getMessage());
                    $result = 'The tool failed to run.';
                }

                // Results go back as their own messages, not bundled into one.
                $working[] = [
                    'role'         => 'tool',
                    'tool_call_id' => isset($call['id']) ? $call['id'] : '',
                    'content'      => (string) $result,
                ];
            }
        }

        // Out of rounds — ask for a plain answer with no tools offered.
        $finalPayload = [
            'model'    => $this->model,
            'messages' => $working,
            ($this->useMaxCompletionTokens ? 'max_completion_tokens' : 'max_tokens') => $maxTokens,
        ];
        if ($this->temperature !== null) {
            $finalPayload['temperature'] = $this->temperature;
        }
        if ($this->reasoningEffort !== null) {
            $finalPayload['reasoning_effort'] = $this->reasoningEffort;
        }
        $final = $this->post($finalPayload);

        $text = isset($final['choices'][0]['message']['content'])
            ? trim((string) $final['choices'][0]['message']['content'])
            : '';

        if ($text === '') {
            throw new ChatApiException('Response contained no text.', 'empty_response');
        }

        return $text;
    }

    /**
     * Translate our tool definitions into OpenAI's nested shape.
     *
     * @param array $tools
     * @return array
     */
    private function convertTools(array $tools)
    {
        $converted = [];

        foreach ($tools as $tool) {
            if (!isset($tool['name'])) {
                continue;
            }

            $converted[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $tool['name'],
                    'description' => isset($tool['description']) ? $tool['description'] : '',
                    // The internal tool shape calls it input_schema; OpenAI
                    // calls the same JSON Schema object parameters.
                    // parameters. Same JSON Schema underneath.
                    'parameters'  => isset($tool['input_schema'])
                        ? $tool['input_schema']
                        : ['type' => 'object', 'properties' => new stdClass()],
                ],
            ];
        }

        return $converted;
    }

    /**
     * @param array $payload
     * @return array
     * @throws ChatApiException
     */
    private function post(array $payload)
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new ChatApiException('Could not encode request as JSON.', 'encode_failed');
        }

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
        ]);

        $body   = curl_exec($ch);
        $errNo  = curl_errno($ch);
        $errMsg = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0) {
            throw new ChatApiException("Network error contacting OpenAI: {$errMsg}", 'network');
        }

        $decoded = json_decode((string) $body, true);

        if ($status < 200 || $status >= 300) {
            $type    = 'http_' . $status;
            $message = "OpenAI API returned HTTP {$status}";

            if (isset($decoded['error']['type'])) {
                $type = $decoded['error']['type'];
            }
            if (isset($decoded['error']['message'])) {
                $message .= ': ' . $decoded['error']['message'];
            }

            if ($status === 429) {
                $type = 'rate_limit_error';
            } elseif ($status >= 500) {
                $type = 'overloaded_error';
            } elseif ($status === 401) {
                $type = 'authentication_error';
            }

            throw new ChatApiException($message, $type, $status);
        }

        if (!is_array($decoded)) {
            throw new ChatApiException('OpenAI returned a non-JSON response.', 'bad_response', $status);
        }

        return $decoded;
    }
}
