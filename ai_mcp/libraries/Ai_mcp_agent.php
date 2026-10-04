<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Ai_mcp_agent_exception extends Exception
{
}

/**
 * The in-app chat assistant: a tool-use loop between the configured AI provider and the
 * same permission-scoped tool registry the MCP endpoint uses.
 *
 * Providers:
 *   anthropic - Claude Messages API (default model claude-opus-5-5)
 *   openai    - any OpenAI-compatible Chat Completions API: OpenAI, Google Gemini
 *               (generativelanguage.googleapis.com/v1beta/openai), OpenRouter, Groq,
 *               DeepSeek, Mistral, a local Ollama, ... selected via base URL.
 *
 * The conversation is kept in the provider's native message format and only ever appended
 * to, so assistant turns (including Claude's thinking blocks) are replayed unchanged.
 */
class Ai_mcp_agent
{
    const MAX_STEPS = 12;
    const MAX_MESSAGES = 120;
    const MAX_TOOL_RESULT_CHARS = 12000;
    const FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

    private $CI;
    private $staff_id;
    private $provider;
    private $model;
    private $api_key;
    private $base_url;

    public function __construct()
    {
        $this->CI = &get_instance();
        $this->CI->load->library(AI_MCP_MODULE_NAME . '/ai_mcp_tools');
        $this->CI->load->model(AI_MCP_MODULE_NAME . '/ai_mcp_model');
        $this->provider = get_option('ai_mcp_provider') === 'openai' ? 'openai' : 'anthropic';
        $this->model = trim(get_option('ai_mcp_model')) ?: ($this->provider === 'anthropic' ? 'claude-opus-5-5' : 'gpt-4o');
        $this->api_key = ai_mcp_get_api_key();
        $this->base_url = rtrim(trim(get_option('ai_mcp_base_url')), '/');
    }

    public function provider()
    {
        return $this->provider;
    }

    /**
     * Handles a new user message. Returns the payload for the chat widget.
     */
    public function send($staff_id, $text)
    {
        $this->staff_id = (int) $staff_id;
        $state = $this->CI->ai_mcp_model->get_conversation($this->staff_id, $this->provider);
        $notice = null;

        if (!empty($state['pending'])) {
            // A new message abandons the action that was waiting for confirmation.
            $this->append_tool_results($state, $state['pending'], array_fill(0, count($state['pending']), [
                'ok' => false, 'error' => 'The user did not confirm this action and sent a new message instead.',
            ]));
            $state['pending'] = null;
        }
        if (count($state['messages']) > self::MAX_MESSAGES) {
            $state = ['messages' => [], 'display' => [], 'pending' => null];
            $notice = _l('ai_mcp_chat_new_conversation_notice');
        }

        $last = end($state['messages']);
        if ($this->provider === 'anthropic' && $last && $last['role'] === 'user' && is_array($last['content'])) {
            // Unsent tool results from an abandoned confirmation: add the text to that same turn.
            $state['messages'][count($state['messages']) - 1]['content'][] = ['type' => 'text', 'text' => $text];
        } else {
            $state['messages'][] = ['role' => 'user', 'content' => $text];
        }
        $state['display'][] = ['role' => 'user', 'text' => $text];

        $result = $this->loop($state);
        if ($notice) {
            $result['notice'] = $notice;
        }

        return $result;
    }

    /**
     * Approves or rejects the write actions the assistant asked to perform.
     */
    public function confirm($staff_id, $approved)
    {
        $this->staff_id = (int) $staff_id;
        $state = $this->CI->ai_mcp_model->get_conversation($this->staff_id, $this->provider);
        if (empty($state['pending'])) {
            throw new Ai_mcp_agent_exception(_l('ai_mcp_chat_nothing_pending'));
        }
        $calls = $state['pending'];
        $state['pending'] = null;

        $results = [];
        $actions = [];
        foreach ($calls as $call) {
            if ($approved) {
                $result = $this->execute_tool($call);
                $actions[] = $this->action_summary($call, $result);
            } else {
                $result = ['ok' => false, 'error' => 'The user declined this action. Do not retry it unless they ask.'];
            }
            $results[] = $result;
        }
        $this->append_tool_results($state, $calls, $results);
        $state['display'][] = ['role' => 'system', 'text' => $approved ? _l('ai_mcp_chat_approved') : _l('ai_mcp_chat_declined')];

        $out = $this->loop($state);
        $out['actions'] = array_merge($actions, $out['actions']);

        return $out;
    }

    public function reset($staff_id)
    {
        $this->CI->ai_mcp_model->delete_conversation((int) $staff_id);
    }

    public function history($staff_id)
    {
        $state = $this->CI->ai_mcp_model->get_conversation((int) $staff_id, $this->provider);

        return [
            'display' => array_slice($state['display'], -60),
            'pending' => !empty($state['pending']) ? $this->describe_pending($state['pending']) : null,
        ];
    }

    /**
     * One-shot request used by the settings page "Test connection" button.
     */
    public function test()
    {
        $this->staff_id = (int) get_staff_user_id();
        $messages = [['role' => 'user', 'content' => 'Reply with the single word OK.']];
        $response = $this->complete('You are a connection test.', $messages, []);

        return trim($response['text']) ?: '(empty reply)';
    }

    // ---------------------------------------------------------------------------------

    private function loop(&$state)
    {
        $tools = $this->CI->ai_mcp_tools->for_staff($this->staff_id);
        $definitions = $tools->agent_definitions();
        $confirm_writes = get_option('ai_mcp_confirm_writes') == '1';
        $actions = [];
        $reply = '';

        try {
            for ($step = 0; $step < self::MAX_STEPS; $step++) {
                $response = $this->complete($this->system_prompt(), $state['messages'], $definitions);
                if (!($response['refused'] && empty($response['assistant_message']['content']))) {
                    $state['messages'][] = $response['assistant_message'];
                }
                if ($response['text'] !== '') {
                    $reply .= ($reply === '' ? '' : "\n\n") . $response['text'];
                }

                if ($response['refused']) {
                    $reply = $reply ?: _l('ai_mcp_chat_refused');

                    break;
                }
                if (empty($response['tool_calls'])) {
                    if ($response['truncated']) {
                        $reply .= "\n\n" . _l('ai_mcp_chat_truncated');
                    }

                    break;
                }

                $calls = $response['tool_calls'];
                $needs_confirmation = false;
                foreach ($calls as $call) {
                    if ($confirm_writes && $tools->is_write($call['name'])) {
                        $needs_confirmation = true;
                    }
                }
                if ($needs_confirmation) {
                    $state['pending'] = $calls;
                    if ($reply !== '') {
                        $state['display'][] = ['role' => 'assistant', 'text' => $reply];
                    }
                    $this->save($state);

                    return ['reply' => $reply, 'actions' => $actions, 'confirm' => $this->describe_pending($calls)];
                }

                $results = [];
                foreach ($calls as $call) {
                    $result = $this->execute_tool($call);
                    $results[] = $result;
                    $actions[] = $this->action_summary($call, $result);
                }
                $this->append_tool_results($state, $calls, $results);
            }
            if ($step >= self::MAX_STEPS) {
                $reply .= "\n\n" . _l('ai_mcp_chat_too_many_steps');
            }
        } catch (Ai_mcp_agent_exception $e) {
            $this->save($state);

            return ['reply' => null, 'actions' => $actions, 'confirm' => null, 'error' => $e->getMessage()];
        }

        $state['display'][] = ['role' => 'assistant', 'text' => $reply];
        $this->save($state);

        return ['reply' => $reply, 'actions' => $actions, 'confirm' => null];
    }

    private function save($state)
    {
        $state['display'] = array_slice($state['display'], -60);
        $this->CI->ai_mcp_model->save_conversation($this->staff_id, $this->provider, $state);
    }

    private function execute_tool($call)
    {
        return $this->CI->ai_mcp_tools->for_staff($this->staff_id)->execute($call['name'], $call['args'], 'chat');
    }

    private function action_summary($call, $result)
    {
        return [
            'tool'  => $call['name'],
            'ok'    => $result['ok'],
            'error' => $result['ok'] ? null : $result['error'],
        ];
    }

    private function describe_pending($calls)
    {
        $out = [];
        foreach ($calls as $call) {
            $out[] = ['tool' => $call['name'], 'args' => $call['args']];
        }

        return $out;
    }

    private function system_prompt()
    {
        $prompt = 'You are the AI assistant inside the Perfex CRM of ' . get_option('companyname') . '. '
            . 'You are helping staff member "' . ai_mcp_staff_name($this->staff_id) . '". Today is ' . date('l, Y-m-d') . '. '
            . 'You act through the provided tools, which run with exactly this person\'s CRM permissions: if a tool is missing or returns a permission error, tell them they lack access rather than looking for a workaround. '
            . 'Look up IDs with search_crm, list_* or get_reference_data instead of guessing them, and never invent CRM data. '
            . 'Before creating, changing or deleting anything, make sure you have the details you need; ask a short question if something important is ambiguous. '
            . 'Keep answers short and scannable. Use Markdown: bullet lists, **bold** for key figures, and link records with the "url" values tools return. Dates in tools are YYYY-MM-DD.';
        $extra = trim((string) get_option('ai_mcp_system_prompt'));
        if ($extra !== '') {
            $prompt .= "\n\nAdditional instructions from the organisation:\n" . $extra;
        }

        return $prompt;
    }

    private function encode_result($result)
    {
        $text = $result['ok']
            ? json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : 'ERROR: ' . $result['error'];
        if (mb_strlen($text) > self::MAX_TOOL_RESULT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_TOOL_RESULT_CHARS) . '... [truncated; use filters or a smaller limit]';
        }

        return $text;
    }

    // ---------------------------------------------------------------------------------
    // Provider adapters
    // ---------------------------------------------------------------------------------

    /**
     * Returns ['assistant_message' => native message to append, 'text' => string,
     *          'tool_calls' => [['id','name','args']], 'refused' => bool, 'truncated' => bool]
     */
    private function complete($system, $messages, $definitions)
    {
        if ($this->api_key === '') {
            throw new Ai_mcp_agent_exception(_l('ai_mcp_chat_no_key'));
        }

        return $this->provider === 'anthropic'
            ? $this->complete_anthropic($system, $messages, $definitions)
            : $this->complete_openai($system, $messages, $definitions);
    }

    private function append_tool_results(&$state, $calls, $results)
    {
        if ($this->provider === 'anthropic') {
            // All results for one assistant turn go back in a single user message.
            $blocks = [];
            foreach ($calls as $i => $call) {
                $blocks[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $call['id'],
                    'content'     => $this->encode_result($results[$i]),
                    'is_error'    => !$results[$i]['ok'],
                ];
            }
            $state['messages'][] = ['role' => 'user', 'content' => $blocks];

            return;
        }

        foreach ($calls as $i => $call) {
            $state['messages'][] = [
                'role'         => 'tool',
                'tool_call_id' => $call['id'],
                'content'      => $this->encode_result($results[$i]),
            ];
        }
    }

    private function complete_anthropic($system, $messages, $definitions)
    {
        $tools = [];
        foreach ($definitions as $def) {
            $tools[] = ['name' => $def['name'], 'description' => $def['description'], 'input_schema' => $def['schema']];
        }

        $body = [
            'model'      => $this->model,
            'max_tokens' => 16000,
            'system'     => $system,
            'messages'   => $this->anthropic_normalize($messages),
        ];
        if (!empty($tools)) {
            $body['tools'] = $tools;
        }

        $headers = [
            'x-api-key: ' . $this->api_key,
            'anthropic-version: 2023-06-01',
        ];
        $base = $this->base_url ?: 'https://api.anthropic.com';
        // Server-side fallback: if the model's safety classifier declines, the API retries on
        // Anthropic's recommended fallback model instead of returning a refusal.
        if ($this->base_url === '' && in_array($this->model, self::FALLBACK_MODELS, true)) {
            $body['fallbacks'] = 'default';
            $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
        }

        $data = $this->http_post($base . '/v1/messages', $headers, $body);

        $stop = $data['stop_reason'] ?? null;
        $content = isset($data['content']) && is_array($data['content']) ? $data['content'] : [];
        $text = '';
        $calls = [];
        if ($stop !== 'refusal') {
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text .= $block['text'];
                } elseif (($block['type'] ?? '') === 'tool_use') {
                    $calls[] = ['id' => $block['id'], 'name' => $block['name'], 'args' => is_array($block['input']) ? $block['input'] : []];
                }
            }
        }

        return [
            'assistant_message' => ['role' => 'assistant', 'content' => $content],
            'text'              => trim($text),
            'tool_calls'        => $stop === 'tool_use' ? $calls : [],
            'refused'           => $stop === 'refusal',
            'truncated'         => $stop === 'max_tokens',
        ];
    }

    /**
     * json_decode(..., true) turns {} into [], which the API rejects for tool inputs.
     */
    private function anthropic_normalize($messages)
    {
        foreach ($messages as &$message) {
            if (!is_array($message['content'])) {
                continue;
            }
            foreach ($message['content'] as &$block) {
                if (($block['type'] ?? '') === 'tool_use' && (empty($block['input']) || !is_array($block['input']))) {
                    $block['input'] = new stdClass();
                }
            }
        }
        // A refusal can leave an assistant turn with empty content, which cannot be replayed.
        return array_values(array_filter($messages, function ($m) {
            return !($m['role'] === 'assistant' && $m['content'] === []);
        }));
    }

    private function complete_openai($system, $messages, $definitions)
    {
        $tools = [];
        foreach ($definitions as $def) {
            $tools[] = ['type' => 'function', 'function' => [
                'name' => $def['name'], 'description' => $def['description'], 'parameters' => $def['schema'],
            ]];
        }
        $body = [
            'model'    => $this->model,
            'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages),
        ];
        if (!empty($tools)) {
            $body['tools'] = $tools;
            $body['tool_choice'] = 'auto';
        }

        $base = $this->base_url ?: 'https://api.openai.com/v1';
        $data = $this->http_post($base . '/chat/completions', ['Authorization: Bearer ' . $this->api_key], $body);

        $choice = $data['choices'][0] ?? null;
        if (!$choice) {
            throw new Ai_mcp_agent_exception(_l('ai_mcp_chat_bad_response'));
        }
        $message = $choice['message'];
        $assistant = ['role' => 'assistant', 'content' => $message['content'] ?? null];
        $calls = [];
        if (!empty($message['tool_calls'])) {
            $assistant['tool_calls'] = $message['tool_calls'];
            foreach ($message['tool_calls'] as $tc) {
                $args = json_decode($tc['function']['arguments'] ?? '{}', true);
                $calls[] = ['id' => $tc['id'], 'name' => $tc['function']['name'], 'args' => is_array($args) ? $args : []];
            }
        }

        return [
            'assistant_message' => $assistant,
            'text'              => trim((string) ($message['content'] ?? '')),
            'tool_calls'        => $calls,
            'refused'           => !empty($message['refusal']),
            'truncated'         => ($choice['finish_reason'] ?? '') === 'length',
        ];
    }

    private function http_post($url, $headers, $body)
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 170,
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new Ai_mcp_agent_exception(_l('ai_mcp_chat_network_error') . ' ' . $curl_error);
        }
        $data = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $message = $data['error']['message'] ?? (is_string($data['error'] ?? null) ? $data['error'] : mb_substr($raw, 0, 300));
            if ($status === 401 || $status === 403) {
                $message = _l('ai_mcp_chat_auth_error') . ' (' . $message . ')';
            } elseif ($status === 429) {
                $message = _l('ai_mcp_chat_rate_limited') . ' (' . $message . ')';
            }
            log_message('error', 'ai_mcp provider HTTP ' . $status . ': ' . mb_substr($raw, 0, 1000));

            throw new Ai_mcp_agent_exception('AI provider error ' . $status . ': ' . $message);
        }
        if (!is_array($data)) {
            throw new Ai_mcp_agent_exception(_l('ai_mcp_chat_bad_response'));
        }

        return $data;
    }
}
