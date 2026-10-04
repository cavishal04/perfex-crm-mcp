<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Model Context Protocol endpoint (Streamable HTTP transport, JSON responses, stateless).
 *
 *   POST {site}/ai_mcp/mcp              with header  Authorization: Bearer pmcp_...
 *   POST {site}/ai_mcp/mcp/t/pmcp_...   token in the URL, for clients that cannot send headers
 *
 * Each request is authenticated with a staff member's personal token and executed as that
 * staff member, so the AI gets exactly that person's CRM permissions.
 */
class Mcp extends App_Controller
{
    private $supported_versions = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private $staff_id;
    private $token_id;

    public function __construct()
    {
        parent::__construct();
        $this->load->model(AI_MCP_MODULE_NAME . '/ai_mcp_model');
        $this->load->library(AI_MCP_MODULE_NAME . '/ai_mcp_tools');
    }

    public function index()
    {
        $this->handle($this->bearer_token());
    }

    public function t($token = '')
    {
        $this->handle($token);
    }

    private function handle($token)
    {
        ob_start();
        $method = strtoupper($this->input->method());

        if ($method === 'OPTIONS') {
            $this->send(204, null);

            return;
        }
        if ($method === 'GET' && strpos((string) $this->input->get_request_header('Accept'), 'text/event-stream') === false) {
            // Opened in a browser: show a diagnostic instead of the bare 405.
            $row = $token ? $this->ai_mcp_model->find_token($token) : null;
            $this->send(200, [
                'server'          => 'Perfex CRM MCP endpoint (ai_mcp ' . AI_MCP_VERSION . ')',
                'routing'         => 'ok',
                'module_enabled'  => ai_mcp_is_enabled() && ai_mcp_tenant_allowed(),
                'token'           => $token === '' ? 'not provided' : ($row ? 'valid' : 'invalid or revoked'),
                'staff_access'    => $row ? ai_mcp_staff_has_access($row['staff_id']) : null,
                'auth_header_seen'=> $this->bearer_token() !== '',
                'next_step'       => 'AI apps must POST JSON-RPC to this URL. If POST fails with 403 "action not allowed", add ai_mcp/mcp.* to csrf_exclude_uris.',
            ]);

            return;
        }
        if ($method !== 'POST') {
            // No server-initiated SSE stream: the spec allows 405 for GET.
            header('Allow: POST');
            $this->send(405, ['error' => 'Use POST for MCP requests.']);

            return;
        }
        if (!ai_mcp_is_enabled() || !ai_mcp_tenant_allowed()) {
            $this->send(403, $this->rpc_error(null, -32001, 'The AI connector is not enabled for this CRM.'));

            return;
        }

        $row = $this->ai_mcp_model->find_token($token);
        if (!$row) {
            header('WWW-Authenticate: Bearer realm="Perfex CRM MCP"');
            $this->send(401, $this->rpc_error(null, -32001, 'Invalid or revoked access token.'));

            return;
        }
        // Access is re-checked on every request, so deactivating a staff member or removing
        // the permission takes effect immediately for all their tokens.
        if (!ai_mcp_staff_has_access($row['staff_id'])) {
            $this->send(403, $this->rpc_error(null, -32001, 'This staff member does not have access to the AI connector.'));

            return;
        }

        $this->staff_id = (int) $row['staff_id'];
        $this->token_id = (int) $row['id'];
        $this->ai_mcp_model->touch_token($this->token_id, $this->input->ip_address());

        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $this->send(400, $this->rpc_error(null, -32700, 'Parse error'));

            return;
        }

        $this->bind_session();
        try {
            $is_batch = array_keys($body) === range(0, count($body) - 1);
            $messages = $is_batch ? $body : [$body];
            $responses = [];
            foreach ($messages as $message) {
                $response = $this->dispatch($message);
                if ($response !== null) {
                    $responses[] = $response;
                }
            }
        } finally {
            $this->unbind_session();
        }

        if (empty($responses)) {
            $this->send(202, null);

            return;
        }
        $this->send(200, $is_batch ? $responses : $responses[0]);
    }

    private function dispatch($message)
    {
        if (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || empty($message['method'])) {
            return $this->rpc_error($message['id'] ?? null, -32600, 'Invalid Request');
        }
        $has_id = array_key_exists('id', $message);
        $id = $has_id ? $message['id'] : null;
        $params = isset($message['params']) && is_array($message['params']) ? $message['params'] : [];

        // Notifications (no id) never get a response.
        if (!$has_id) {
            return null;
        }

        switch ($message['method']) {
            case 'initialize':
                $requested = $params['protocolVersion'] ?? '';
                $version = in_array($requested, $this->supported_versions, true) ? $requested : $this->supported_versions[0];

                return $this->rpc_result($id, [
                    'protocolVersion' => $version,
                    'capabilities'    => ['tools' => ['listChanged' => false]],
                    'serverInfo'      => ['name' => 'perfex-crm', 'title' => get_option('companyname') . ' CRM', 'version' => AI_MCP_VERSION],
                    'instructions'    => $this->instructions(),
                ]);

            case 'ping':
                return $this->rpc_result($id, new stdClass());

            case 'tools/list':
                return $this->rpc_result($id, ['tools' => $this->ai_mcp_tools->for_staff($this->staff_id)->mcp_definitions()]);

            case 'tools/call':
                $name = (string) ($params['name'] ?? '');
                $args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];
                $result = $this->ai_mcp_tools->for_staff($this->staff_id)->execute($name, $args, 'mcp', $this->token_id);

                return $this->rpc_result($id, [
                    'content' => [[
                        'type' => 'text',
                        'text' => $result['ok']
                            ? json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                            : $result['error'],
                    ]],
                    'isError' => !$result['ok'],
                ]);

            case 'resources/list':
                return $this->rpc_result($id, ['resources' => []]);

            case 'prompts/list':
                return $this->rpc_result($id, ['prompts' => []]);

            default:
                return $this->rpc_error($id, -32601, 'Method not found: ' . $message['method']);
        }
    }

    private function instructions()
    {
        $name = ai_mcp_staff_name($this->staff_id);

        return 'You are connected to the Perfex CRM of ' . get_option('companyname') . ' as staff member "' . $name . '". '
            . 'You can only see and change what this person is permitted to in the CRM. '
            . 'Use search_crm or the list_* tools to find record IDs before calling get_* or write tools, and get_reference_data for status/source/tax/category IDs. '
            . 'Dates are YYYY-MM-DD. Confirm with the user before creating, changing or deleting records.';
    }

    /**
     * Perfex models read the acting user from the session (get_staff_user_id()), so the
     * request runs inside a throw-away session for the token's staff member.
     */
    private function bind_session()
    {
        $this->session->set_userdata([
            'staff_user_id'   => $this->staff_id,
            'staff_logged_in' => true,
        ]);
    }

    private function unbind_session()
    {
        $this->session->unset_userdata(['staff_user_id', 'staff_logged_in']);
        $this->session->sess_destroy();
    }

    private function bearer_token()
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $key => $value) {
                if (strtolower($key) === 'authorization') {
                    $header = $value;
                }
            }
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
            return $m[1];
        }

        return '';
    }

    private function rpc_result($id, $result)
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function rpc_error($id, $code, $message)
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private function send($status, $payload)
    {
        // Drop any stray output (PHP notices from core models) so the JSON stays valid.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Cache-Control: no-store');
        if ($payload !== null) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
}
